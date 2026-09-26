<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Mcp;

use MagoAssistant\Mago\Api\Config\RepositoryInterface as MagoConfig;
use MagoAssistant\Mago\Logger\DebugLogger;

// phpcs:disable Magento2.Security.InsecureFunction, Magento2.Functions.DiscouragedFunction, Magento2.Exceptions.TryProcessSystemResources
// A JSON-RPC session over a child process needs proc_open and raw pipes: Magento\Framework\Shell only
// wraps exec() through a shell, with no stdin, no streaming stdout and no timeout. This is the one file
// in the module that talks to a process; every return value is checked, nothing is silenced.

/**
 * One server process, one deadline, newline-delimited JSON-RPC 2.0 over its pipes.
 */
class StdioSession
{
    private const STDERR_CAP = 65536;

    /** @var resource|null */
    private $process = null;

    /** @var array<int,resource> */
    private array $pipes = [];

    /** @var array<int,resource> streams still worth selecting on */
    private array $readable = [];

    private string $buffer = '';
    private string $stderr = '';
    private float $deadline = 0.0;
    private int $nextId = 0;
    private bool $timedOut = false;

    public function __construct(
        private readonly ServerConfig $server,
        private readonly string $rootDir,
        private readonly int $timeoutSeconds,
        private readonly DebugLogger $debugLogger,
        private readonly MagoConfig $magoConfig
    ) {
    }

    public function start(): void
    {
        $cwd = $this->server->cwd ?? $this->rootDir;
        if (!str_starts_with($cwd, '/')) {
            $cwd = rtrim($this->rootDir, '/') . '/' . $cwd;
        }
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = array_merge(getenv(), $this->server->env);
        $pipes = [];
        // A missing binary makes proc_open() both return false and raise an E_WARNING (posix_spawn()
        // failing with ENOENT); the warning duplicates the McpProcessException thrown just below, so it
        // is caught here instead of leaking into the caller's error log or a PHPUnit warning.
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $process = proc_open($this->server->command, $spec, $pipes, $cwd, $env);
        } finally {
            restore_error_handler();
        }
        if (!is_resource($process)) {
            throw new McpProcessException(sprintf('Could not start MCP server "%s".', $this->server->name));
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $this->process = $process;
        $this->pipes = $pipes;
        $this->readable = [1 => $pipes[1], 2 => $pipes[2]];
        $this->deadline = microtime(true) + $this->timeoutSeconds;
    }

    /**
     * @param array<string,mixed>|\stdClass $params
     * @return array<string,mixed>
     */
    public function request(string $method, array|\stdClass $params): array
    {
        $id = ++$this->nextId;
        $this->write(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
        while (true) {
            $message = $this->readMessage();
            if (isset($message['method'])) {
                $this->answerServerMessage($message);
                continue;
            }
            if (($message['id'] ?? null) !== $id) {
                continue;
            }
            if (isset($message['error'])) {
                $text = (string)($message['error']['message'] ?? 'unknown error');
                throw new McpException(sprintf('%s: %s', $method, $text));
            }
            $result = $message['result'] ?? null;

            return is_array($result) ? $result : [];
        }
    }

    public function notify(string $method): void
    {
        $this->write(['jsonrpc' => '2.0', 'method' => $method]);
    }

    public function close(): void
    {
        if ($this->process === null) {
            return;
        }
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (!$this->timedOut) {
            $waitUntil = microtime(true) + 2.0;
            while (microtime(true) < $waitUntil && proc_get_status($this->process)['running']) {
                usleep(50000);
            }
        }
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process, 9);
        }
        proc_close($this->process);
        $this->process = null;
        $this->pipes = [];
        $this->readable = [];
    }

    /**
     * A notification is ignored; a request from the server is answered so the session cannot stall.
     *
     * @param array<string,mixed> $message
     */
    private function answerServerMessage(array $message): void
    {
        if (!array_key_exists('id', $message)) {
            return;
        }
        if ($message['method'] === 'ping') {
            $this->write(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => new \stdClass()]);

            return;
        }
        $this->write(['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }

    /**
     * @param array<string,mixed> $message
     */
    private function write(array $message): void
    {
        $line = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (!isset($this->pipes[0]) || !is_resource($this->pipes[0]) || fwrite($this->pipes[0], $line) === false) {
            throw new McpProcessException(sprintf('MCP server "%s" closed its input.', $this->server->name));
        }
        fflush($this->pipes[0]);
    }

    /**
     * @return array<string,mixed>
     */
    private function readMessage(): array
    {
        while (true) {
            $newline = strpos($this->buffer, "\n");
            if ($newline !== false) {
                $line = trim(substr($this->buffer, 0, $newline));
                $this->buffer = substr($this->buffer, $newline + 1);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
                $this->debug('MCP non-JSON line', ['server' => $this->server->name, 'line' => substr($line, 0, 200)]);
                continue;
            }
            $this->fill();
        }
    }

    private function fill(): void
    {
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) {
            throw $this->timeout();
        }
        $read = array_values($this->readable);
        $write = null;
        $except = null;
        $seconds = (int)floor($remaining);
        $ready = stream_select($read, $write, $except, $seconds, (int)(($remaining - $seconds) * 1e6));
        if ($ready === false) {
            throw new McpProcessException(sprintf('Lost the pipes of MCP server "%s".', $this->server->name));
        }
        if ($ready === 0) {
            throw $this->timeout();
        }
        foreach ($read as $stream) {
            $chunk = fread($stream, 65536);
            if ($chunk === false || $chunk === '') {
                if (feof($stream) && $stream === $this->pipes[2]) {
                    unset($this->readable[2]); // a closed stderr would otherwise be "readable" forever
                }
                continue;
            }
            if ($stream === $this->pipes[2]) {
                $this->stderr = substr($this->stderr . $chunk, 0, self::STDERR_CAP);
                $this->debug('MCP stderr', ['server' => $this->server->name, 'text' => trim($chunk)]);
                continue;
            }
            $this->buffer .= $chunk;
        }
        if (strpos($this->buffer, "\n") === false && feof($this->pipes[1])) {
            // The process may already have been closed; its exit code is then unknown.
            $status = is_resource($this->process) ? proc_get_status($this->process) : ['running' => true, 'exitcode' => -1];
            $first = strtok($this->stderr, "\n") ?: 'no output';
            throw new McpProcessException(sprintf(
                'MCP server "%s" exited (code %s) before answering: %s',
                $this->server->name,
                $status['running'] ? '?' : (string)$status['exitcode'],
                $first
            ));
        }
    }

    private function timeout(): McpTimeoutException
    {
        $this->timedOut = true;

        return new McpTimeoutException(sprintf(
            'MCP server "%s" timed out after %d s.',
            $this->server->name,
            $this->timeoutSeconds
        ));
    }

    /**
     * @param array<string,mixed> $data
     */
    private function debug(string $type, array $data): void
    {
        if ($this->magoConfig->isDebugEnabled()) {
            $this->debugLogger->addLog($type, $data);
        }
    }
}
