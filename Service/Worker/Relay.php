<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Worker;

use MagoAssistant\Mcp\Service\Mcp\ServerConfig;

// phpcs:disable Magento2.Security.InsecureFunction, Magento2.Functions.DiscouragedFunction, Magento2.Exceptions.TryProcessSystemResources
// The MCP worker's core: it runs on the command line (bin/magento mago:mcp:serve), where starting a
// process is allowed, so that a web request never has to. Raw sockets and pipes, one stream_select loop,
// nothing blocking; no Magento dependency so it is tested as it runs.

/**
 * Accepts a connection, checks its signed request, starts the named local MCP server and relays bytes
 * between the socket and the server's stdin and stdout until either side ends.
 *
 * The relay does not read MCP. It only knows: a client names a server row, the resolver returns that
 * row's command when the row is an enabled local server, and the client's bytes go to that process.
 */
final class Relay
{
    /** Bytes waiting in one direction before reading from the source pauses */
    private const BUFFER_CAP = 1048576;
    private const LINE_CAP = 8192;
    private const STDERR_CAP = 2000;
    private const STOP_GRACE = 30.0;
    /** How long a closing connection may take to flush its last bytes */
    private const FLUSH_GRACE = 5.0;
    /** How long to wait for a process whose stdout closed to exit, or the reverse, before ending the call */
    private const EXIT_GRACE = 1.0;
    /** Connections still in their handshake; separate from the call slots so idle ones cannot take those */
    private const HANDSHAKE_SLOTS = 16;
    /** Longest call a client may ask for */
    private const MAX_TIMEOUT = 3600;

    /** @var resource|null */
    private $listener = null;

    /**
     * @var array<int, array{
     *     socket: resource, phase: string, nonce: string, clientNonce: string, key: string, line: string,
     *     deadline: float, process: resource|null, pid: int|null, stdin: resource|null, stdout: resource|null,
     *     stderr: resource|null, toChild: string, toClient: string, stderrText: string, stdoutEofAt: float|null,
     *     exitedAt: float|null, exitCode: int|null, server: string
     * }>
     */
    private array $connections = [];

    /** @var array<int, array{0: int|string, 1: string}> stream id => [connection id or 'listener', role] */
    private array $index = [];

    private bool $stopping = false;
    private float $stopDeadline = 0.0;
    private readonly int $started;
    private readonly ?string $setsid;

    /**
     * @param \Closure(string): ?ServerConfig $resolver an enabled local (stdio) server by name, or null
     * @param \Closure(): string[] $keys Handshake::keys() of this install's crypt key, read on every request so
     *        a changed crypt key takes effect without restarting the worker
     * @param string $rootDir Magento root; working directory of a server without its own
     * @param \Closure(string): void|null $log
     * @param string $build what the install was built from (BuildIdentity), reported by status
     */
    public function __construct(
        private readonly \Closure $resolver,
        private readonly \Closure $keys,
        private readonly string $rootDir,
        private readonly int $maxConnections = 8,
        private readonly ?\Closure $log = null,
        private readonly float $handshakeTimeout = 5.0,
        private readonly string $build = ''
    ) {
        $this->started = time();
        // A server is started as the leader of its own process group, so the processes it starts itself
        // (an npx or shell launcher) end with it.
        $setsid = null;
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $candidate) {
            if (is_executable($candidate)) {
                $setsid = $candidate;
                break;
            }
        }
        $this->setsid = function_exists('posix_kill') ? $setsid : null;
    }

    /**
     * @return string the bound address, host:port
     * @throws \RuntimeException when the address cannot be bound
     */
    public function listen(string $address): string
    {
        $errno = 0;
        $error = '';
        $listener = $this->quietly(static function () use ($address, &$errno, &$error) {
            return stream_socket_server('tcp://' . $address, $errno, $error);
        });
        if (!is_resource($listener)) {
            throw new \RuntimeException(sprintf('Cannot listen on %s: %s', $address, $error ?: 'unknown error'));
        }
        stream_set_blocking($listener, false);
        $this->listener = $listener;

        return (string)stream_socket_get_name($listener, false);
    }

    /**
     * Stop now: close the listener and end every call, as on SIGTERM.
     */
    public function stopNow(): void
    {
        $this->beginStop(0.0);
    }

    /**
     * Serve until a stop request has drained.
     */
    public function run(): void
    {
        if ($this->listener === null) {
            throw new \LogicException('Call listen() before run().');
        }
        while (true) {
            // Stop once every connection has ended, including the one carrying the stop request's answer.
            if ($this->stopping && ($this->connections === [] || microtime(true) > $this->stopDeadline)) {
                foreach (array_keys($this->connections) as $id) {
                    $this->drop($id);
                }

                return;
            }
            [$read, $write] = $this->interest();
            $timeout = $this->nextTimeout();
            if ($read === [] && $write === []) {
                usleep((int)($timeout * 1e6));
            } else {
                $except = null;
                $seconds = (int)floor($timeout);
                $microseconds = (int)(($timeout - $seconds) * 1e6);
                $ready = $this->quietly(static function () use (&$read, &$write, &$except, $seconds, $microseconds) {
                    return stream_select($read, $write, $except, $seconds, $microseconds);
                });
                if ($ready === false) {
                    usleep(10000); // interrupted by a signal
                } else {
                    foreach ($read as $stream) {
                        $this->onReadable($stream);
                    }
                    foreach ($write as $stream) {
                        $this->onWritable($stream);
                    }
                }
            }
            $this->housekeeping();
        }
    }

    /**
     * @return array{0: resource[], 1: resource[]}
     */
    private function interest(): array
    {
        $read = [];
        $write = [];
        $this->index = [];
        if ($this->listener !== null) {
            $read[] = $this->listener;
            $this->index[get_resource_id($this->listener)] = ['listener', 'listener'];
        }
        foreach ($this->connections as $id => $c) {
            $this->index[get_resource_id($c['socket'])] = [$id, 'socket'];
            // Always read the client, so a client that leaves is noticed at once.
            if ($c['phase'] !== 'closing') {
                $read[] = $c['socket'];
            }
            if ($c['toClient'] !== '') {
                $write[] = $c['socket'];
            }
            if ($c['phase'] !== 'relay') {
                continue;
            }
            if ($c['stdout'] !== null && $c['stdoutEofAt'] === null && strlen($c['toClient']) < self::BUFFER_CAP) {
                $read[] = $c['stdout'];
                $this->index[get_resource_id($c['stdout'])] = [$id, 'stdout'];
            }
            if ($c['stderr'] !== null) {
                $read[] = $c['stderr'];
                $this->index[get_resource_id($c['stderr'])] = [$id, 'stderr'];
            }
            if ($c['stdin'] !== null && $c['toChild'] !== '') {
                $write[] = $c['stdin'];
                $this->index[get_resource_id($c['stdin'])] = [$id, 'stdin'];
            }
        }

        return [$read, $write];
    }

    /**
     * @param resource $stream
     */
    private function onReadable($stream): void
    {
        [$id, $role] = $this->index[get_resource_id($stream)] ?? [null, ''];
        if ($role === 'listener') {
            $this->accept();

            return;
        }
        if (!is_int($id) || !isset($this->connections[$id])) {
            return;
        }
        $chunk = fread($stream, 65536);
        $eof = ($chunk === false || $chunk === '') && feof($stream);
        $chunk = (string)$chunk;
        $c = &$this->connections[$id];
        switch ($role) {
            case 'socket':
                if ($eof) {
                    unset($c);
                    $this->drop($id); // the client is gone; so is its server

                    return;
                }
                if ($c['phase'] === 'handshake') {
                    $c['line'] .= $chunk;
                    $newline = strpos($c['line'], "\n");
                    if ($newline === false) {
                        if (strlen($c['line']) > self::LINE_CAP) {
                            unset($c);
                            $this->fail($id, 'request too long');
                        }

                        return;
                    }
                    $request = substr($c['line'], 0, $newline);
                    $rest = substr($c['line'], $newline + 1);
                    $c['line'] = '';
                    unset($c);
                    $this->handle($id, $request, $rest);

                    return;
                }
                if ($c['phase'] === 'relay') {
                    if (strlen($c['toChild']) + strlen($chunk) > self::BUFFER_CAP) {
                        // The server has stopped reading its input: the call cannot go on.
                        $this->log(sprintf('"%s" stopped reading its input; ended the call', $c['server']));
                        unset($c);
                        $this->drop($id);

                        return;
                    }
                    $c['toChild'] .= $chunk;
                }

                return;
            case 'stdout':
                if ($eof) {
                    $c['stdoutEofAt'] = microtime(true);
                } else {
                    $c['toClient'] .= $chunk;
                }

                return;
            case 'stderr':
                if ($eof) {
                    fclose($stream);
                    $c['stderr'] = null;
                } else {
                    $c['stderrText'] = substr($c['stderrText'] . $chunk, 0, self::STDERR_CAP);
                }

                return;
        }
    }

    /**
     * @param resource $stream
     */
    private function onWritable($stream): void
    {
        [$id, $role] = $this->index[get_resource_id($stream)] ?? [null, ''];
        if (!is_int($id) || !isset($this->connections[$id])) {
            return;
        }
        $c = &$this->connections[$id];
        if ($role === 'socket' && $c['toClient'] !== '') {
            $toClient = $c['toClient'];
            $written = $this->quietly(static fn () => fwrite($stream, $toClient));
            if ($written === false) {
                unset($c);
                $this->drop($id);

                return;
            }
            $c['toClient'] = (string)substr($c['toClient'], (int)$written);
            if ($c['toClient'] === '' && $c['phase'] === 'closing') {
                unset($c);
                $this->drop($id);
            }

            return;
        }
        if ($role === 'stdin' && $c['toChild'] !== '') {
            $toChild = $c['toChild'];
            $written = $this->quietly(static fn () => fwrite($stream, $toChild));
            // A server that closed its input will show up as an ended stdout; drop what it cannot take.
            $c['toChild'] = $written === false ? '' : (string)substr($c['toChild'], (int)$written);
        }
    }

    private function accept(): void
    {
        if ($this->listener === null) {
            return;
        }
        $listener = $this->listener;
        $socket = $this->quietly(static fn () => stream_socket_accept($listener, 0));
        if (!is_resource($socket)) {
            return;
        }
        $waiting = count(array_filter($this->connections, static fn (array $c): bool => $c['phase'] === 'handshake'));
        if ($waiting >= self::HANDSHAKE_SLOTS) {
            $busy = $this->json(['ok' => false, 'error' => 'busy']);
            $this->quietly(static fn () => fwrite($socket, $busy));
            fclose($socket);
            $this->log('refused a connection: too many unfinished handshakes');

            return;
        }
        stream_set_blocking($socket, false);
        $nonce = Handshake::nonce();
        $this->connections[get_resource_id($socket)] = [
            'socket' => $socket,
            'phase' => 'handshake',
            'nonce' => $nonce,
            'clientNonce' => '',
            'key' => '',
            'line' => '',
            'deadline' => microtime(true) + $this->handshakeTimeout,
            'process' => null,
            'pid' => null,
            'stdin' => null,
            'stdout' => null,
            'stderr' => null,
            'toChild' => '',
            'toClient' => $this->json(['v' => Handshake::PROTOCOL_VERSION, 'nonce' => $nonce]),
            'stderrText' => '',
            'stdoutEofAt' => null,
            'exitedAt' => null,
            'exitCode' => null,
            'server' => '',
        ];
    }

    private function handle(int $id, string $line, string $rest): void
    {
        $request = json_decode($line, true);
        $request = is_array($request) ? $request : [];
        $op = is_string($request['op'] ?? null) ? $request['op'] : '';
        $server = is_string($request['server'] ?? null) ? $request['server'] : '';
        $auth = is_string($request['auth'] ?? null) ? $request['auth'] : '';
        $timeout = is_int($request['timeout'] ?? null) ? $request['timeout'] : 0;
        $clientNonce = $request['cnonce'] ?? null;
        if (!in_array($op, ['open', 'status', 'stop'], true) || !Handshake::isNonce($clientNonce)) {
            $this->fail($id, 'bad request');

            return;
        }
        try {
            $keys = ($this->keys)();
        } catch (\Throwable $e) {
            $this->log('could not read the crypt key: ' . $e->getMessage());
            $keys = [];
        }
        $key = Handshake::verifyRequest($keys, $this->connections[$id]['nonce'], $clientNonce, $op, $server, $timeout, $auth);
        if ($key === null) {
            $this->log(sprintf('refused an unsigned or wrongly signed "%s" request', $op));
            // No proof: the client either has another key or is not this install.
            $this->fail($id, 'not authorised');

            return;
        }
        $this->connections[$id]['clientNonce'] = $clientNonce;
        $this->connections[$id]['key'] = $key;
        if ($op === 'status') {
            $this->finish($id, [
                'ok' => true,
                'root' => realpath($this->rootDir) ?: $this->rootDir,
                'build' => $this->build,
                'pid' => getmypid(),
                'connections' => $this->relayCount(),
                'started' => $this->started,
            ]);

            return;
        }
        if ($op === 'stop') {
            $this->log('stop requested');
            $this->beginStop(self::STOP_GRACE);
            $this->finish($id, ['ok' => true]);

            return;
        }
        $this->open($id, $server, $timeout, $rest);
    }

    private function open(int $id, string $name, int $requestedTimeout, string $rest): void
    {
        if ($this->stopping) {
            $this->fail($id, 'the worker is stopping');

            return;
        }
        if ($this->relayCount() >= $this->maxConnections) {
            $this->fail($id, 'busy');

            return;
        }
        try {
            $server = ($this->resolver)($name);
        } catch (\Throwable $e) {
            $this->log(sprintf('could not look up "%s": %s', $name, $e->getMessage()));
            $server = null;
        }
        if (!$server instanceof ServerConfig || $server->command === []) {
            $this->fail($id, sprintf('"%s" is not an enabled local MCP server.', $name));

            return;
        }
        $cwd = $server->cwd ?? $this->rootDir;
        if (!str_starts_with($cwd, '/')) {
            $cwd = rtrim($this->rootDir, '/') . '/' . $cwd;
        }
        $command = $this->setsid !== null ? [$this->setsid, ...$server->command] : $server->command;
        $pipes = [];
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $process = proc_open(
                $command,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']] + InheritedDescriptors::shadow(),
                $pipes,
                $cwd,
                array_merge(getenv(), $server->env)
            );
        } finally {
            restore_error_handler();
        }
        if (!is_resource($process)) {
            $this->fail($id, sprintf('Could not start MCP server "%s".', $name));

            return;
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        // The client's timeout is signed, so it is the admin's current setting; the row's when it sent none.
        $timeout = $requestedTimeout > 0 ? min($requestedTimeout, self::MAX_TIMEOUT) : $server->timeout;
        $c = &$this->connections[$id];
        $c['phase'] = 'relay';
        $c['server'] = $name;
        $c['process'] = $process;
        $c['pid'] = (int)proc_get_status($process)['pid'];
        $c['stdin'] = $pipes[0];
        $c['stdout'] = $pipes[1];
        $c['stderr'] = $pipes[2];
        $c['toChild'] = $rest;
        $c['deadline'] = microtime(true) + $timeout + 5;
        unset($c);
        $this->answer($id, ['ok' => true]);
        $this->log(sprintf('started "%s"', $name));
    }

    private function housekeeping(): void
    {
        $now = microtime(true);
        foreach (array_keys($this->connections) as $id) {
            $c = $this->connections[$id];
            if ($now > $c['deadline']) {
                $this->log(sprintf('closed a connection at its deadline (%s)', $c['phase']));
                $this->drop($id);
                continue;
            }
            if ($c['phase'] !== 'relay') {
                continue;
            }
            if ($c['exitedAt'] === null && is_resource($c['process'])) {
                $status = proc_get_status($c['process']);
                if (!$status['running']) {
                    // proc_get_status() reports the real exit code only the first time.
                    $this->connections[$id]['exitedAt'] = $now;
                    $this->connections[$id]['exitCode'] = (int)$status['exitcode'];
                    $c = $this->connections[$id];
                }
            }
            $ended = ($c['stdoutEofAt'] !== null && ($c['exitedAt'] !== null || $now - $c['stdoutEofAt'] >= self::EXIT_GRACE))
                || ($c['exitedAt'] !== null && $now - $c['exitedAt'] >= self::EXIT_GRACE);
            if (!$ended) {
                continue;
            }
            // The server ended: report how, then close once that line is sent.
            if (is_resource($c['stderr'])) {
                $tail = fread($c['stderr'], self::STDERR_CAP);
                $c['stderrText'] = substr($c['stderrText'] . (string)$tail, 0, self::STDERR_CAP);
            }
            $this->log(sprintf('"%s" ended (code %s)', $c['server'], $c['exitCode'] === null ? '?' : (string)$c['exitCode']));
            $this->release($id);
            $this->finish($id, [
                'jsonrpc' => '2.0',
                'method' => 'notifications/worker/exit',
                'params' => ['code' => $c['exitCode'], 'stderr' => $c['stderrText']],
            ]);
        }
    }

    /**
     * A signed answer: the client checks it came from this install's worker.
     *
     * @param array<string,mixed> $answer
     */
    private function answer(int $id, array $answer): void
    {
        $c = $this->connections[$id];
        $this->connections[$id]['toClient'] .= $this->json(Handshake::signAnswer($c['key'], $c['nonce'], $c['clientNonce'], $answer));
    }

    /**
     * Send one last answer, then close.
     *
     * @param array<string,mixed> $message
     */
    private function finish(int $id, array $message): void
    {
        if (isset($message['ok'])) {
            $this->answer($id, $message);
        } else {
            $this->connections[$id]['toClient'] .= $this->json($message);
        }
        $this->connections[$id]['phase'] = 'closing';
        $this->connections[$id]['deadline'] = microtime(true) + self::FLUSH_GRACE;
    }

    private function fail(int $id, string $error): void
    {
        $c = $this->connections[$id];
        if ($c['key'] === '') {
            // Before authentication there is nothing to sign with.
            $this->connections[$id]['toClient'] .= $this->json(['ok' => false, 'error' => $error]);
            $this->connections[$id]['phase'] = 'closing';
            $this->connections[$id]['deadline'] = microtime(true) + self::FLUSH_GRACE;

            return;
        }
        $this->finish($id, ['ok' => false, 'error' => $error]);
    }

    private function beginStop(float $grace): void
    {
        $this->stopping = true;
        $this->stopDeadline = microtime(true) + $grace;
        if ($this->listener !== null) {
            fclose($this->listener);
            $this->listener = null;
        }
    }

    /**
     * End the connection's server process and the processes it started, and close its pipes.
     */
    private function release(int $id): void
    {
        $c = &$this->connections[$id];
        foreach (['stdin', 'stdout', 'stderr'] as $pipe) {
            if (is_resource($c[$pipe])) {
                fclose($c[$pipe]);
            }
            $c[$pipe] = null;
        }
        if ($this->setsid !== null && $c['pid'] !== null && $c['pid'] > 0) {
            // The server leads its own process group; this ends what it started too.
            $pid = $c['pid'];
            $this->quietly(static fn () => posix_kill(-$pid, 9));
        }
        if (is_resource($c['process'])) {
            if (proc_get_status($c['process'])['running']) {
                proc_terminate($c['process'], 9);
            }
            proc_close($c['process']);
        }
        $c['process'] = null;
        $c['pid'] = null;
    }

    private function drop(int $id): void
    {
        if (!isset($this->connections[$id])) {
            return;
        }
        $this->release($id);
        if (is_resource($this->connections[$id]['socket'])) {
            fclose($this->connections[$id]['socket']);
        }
        unset($this->connections[$id]);
    }

    private function relayCount(): int
    {
        return count(array_filter($this->connections, static fn (array $c): bool => $c['phase'] === 'relay'));
    }

    private function nextTimeout(): float
    {
        $timeout = 1.0;
        $now = microtime(true);
        foreach ($this->connections as $c) {
            $timeout = min($timeout, max(0.0, $c['deadline'] - $now));
            if ($c['stdoutEofAt'] !== null || $c['exitedAt'] !== null) {
                $timeout = min($timeout, 0.05);
            }
        }

        return max(0.01, $timeout);
    }

    /**
     * @param array<string,mixed> $message
     */
    private function json(array $message): string
    {
        return json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }

    /**
     * Run a stream call whose failure is handled through its return value: a peer that went away makes
     * fwrite() and stream_select() raise warnings the loop already deals with.
     *
     * @template T
     * @param \Closure(): T $call
     * @return T
     */
    private function quietly(\Closure $call): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Only what the worker did. A server's stderr can echo credentials, so it is never logged here; it
     * reaches the admin's session, which masks it.
     */
    private function log(string $message): void
    {
        if ($this->log !== null) {
            ($this->log)($message);
        }
    }
}
