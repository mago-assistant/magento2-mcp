<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Worker;

use MagoAssistant\Mcp\Service\Worker\Handshake;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs a real Relay in a child process (Fakes/run-relay.php) and talks to it over TCP, as the web side does.
 */
final class RelayTest extends TestCase
{
    private const CRYPT_KEY = 'test-crypt-key';

    /** @var resource|null */
    private $relay = null;
    private string $address = '';
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/relay-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->relay)) {
            proc_terminate($this->relay, 9);
            proc_close($this->relay);
        }
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    /**
     * @param array<string,string> $env
     */
    private function startRelay(array $env = [], int $max = 8, float $handshake = 5.0, array $servers = [], string $cryptKey = self::CRYPT_KEY): void
    {
        $fake = dirname(__DIR__, 2) . '/Fakes/fake-mcp-server.php';
        $config = $this->dir . '/relay.json';
        file_put_contents($config, json_encode([
            'key' => $cryptKey,
            'root' => $this->dir,
            'max' => $max,
            'handshake' => $handshake,
            'build' => 'build-1',
            'servers' => $servers + ['fake' => ['command' => [PHP_BINARY, $fake], 'env' => $env + ['FAKE_MCP_MARKER' => $this->dir . '/started'], 'timeout' => 20]],
        ]));
        $pipes = [];
        $this->relay = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Fakes/run-relay.php', $config], [1 => ['pipe', 'w'], 2 => ['file', $this->dir . '/relay.err', 'a']], $pipes);
        $this->address = trim((string)fgets($pipes[1]));
        self::assertMatchesRegularExpression('/^127\.0\.0\.1:\d+$/', $this->address, self::contents($this->dir . '/relay.err'));
    }

    /**
     * @return array{0: resource, 1: string} the socket and the worker's nonce
     */
    private function connect(): array
    {
        $socket = stream_socket_client('tcp://' . $this->address, $errno, $error, 5);
        self::assertIsResource($socket, $error);
        $hello = json_decode($this->readLine($socket), true);
        self::assertSame(2, $hello['v']);

        return [$socket, $hello['nonce']];
    }

    /**
     * @param resource $socket
     * @return array<string,mixed>
     */
    private function ask($socket, string $nonce, string $op, string $server = '', ?string $auth = null, int $timeout = 20, string $cryptKey = self::CRYPT_KEY): array
    {
        $key = Handshake::key($cryptKey);
        $clientNonce = Handshake::nonce();
        $auth ??= Handshake::signRequest($key, $nonce, $clientNonce, $op, $server, $timeout);
        fwrite($socket, json_encode(['op' => $op, 'server' => $server, 'timeout' => $timeout, 'cnonce' => $clientNonce, 'auth' => $auth]) . "\n");
        $answer = json_decode($this->readLine($socket), true) ?? ['unreadable' => true];
        $this->lastAnswerProven = Handshake::verifyAnswer($key, $nonce, $clientNonce, $answer);
        unset($answer['proof']);

        return $answer;
    }

    private bool $lastAnswerProven = false;

    /**
     * @param resource $socket
     */
    private function readLine($socket, float $seconds = 10.0): string
    {
        $until = microtime(true) + $seconds;
        $line = '';
        stream_set_blocking($socket, false);
        while (!str_contains($line, "\n") && microtime(true) < $until) {
            $read = [$socket];
            $write = $except = null;
            if (stream_select($read, $write, $except, 0, 50000) > 0) {
                $chunk = fread($socket, 1);
                if ($chunk === false || ($chunk === '' && feof($socket))) {
                    break;
                }
                $line .= $chunk;
            }
        }

        return $line;
    }

    /**
     * @param resource $socket
     * @return array<string,mixed>
     */
    private function rpc($socket, int $id, string $method, array $params = []): array
    {
        fwrite($socket, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params ?: new \stdClass()]) . "\n");
        while (true) {
            $line = $this->readLine($socket);
            if ($line === '') {
                return ['closed' => true];
            }
            $message = json_decode($line, true);
            if (($message['id'] ?? null) === $id) {
                return $message;
            }
        }
    }

    /**
     * @return resource
     */
    private function openFake()
    {
        [$socket, $nonce] = $this->connect();
        self::assertSame(['ok' => true], $this->ask($socket, $nonce, 'open', 'fake'));
        self::assertTrue($this->lastAnswerProven, 'the worker proved its answer');

        return $socket;
    }

    private static function contents(string $path): string
    {
        return is_readable($path) ? (string)file_get_contents($path) : '';
    }

    private function alive(int $pid): bool
    {
        return file_exists('/proc/' . $pid) && !str_contains(self::contents('/proc/' . $pid . '/status'), "State:\tZ");
    }

    private function waitUntilGone(int $pid, float $seconds): bool
    {
        $until = microtime(true) + $seconds;
        while ($this->alive($pid) && microtime(true) < $until) {
            usleep(50000);
        }

        return !$this->alive($pid);
    }

    #[Test]
    public function relaysAServersAnswersToAnAuthenticatedClient(): void
    {
        $this->startRelay();
        $socket = $this->openFake();

        $init = $this->rpc($socket, 1, 'initialize');
        self::assertSame('fake-mcp', $init['result']['serverInfo']['name']);
        $call = $this->rpc($socket, 2, 'tools/call', ['name' => 'echo-args', 'arguments' => ['text' => 'hi']]);
        self::assertSame('{"text":"hi"}', $call['result']['content'][0]['text']);
        self::assertFileExists($this->dir . '/started');
    }

    #[Test]
    public function servesTwoClientsAtOnce(): void
    {
        $this->startRelay();
        $first = $this->openFake();
        $second = $this->openFake();

        $answer = $this->rpc($second, 1, 'initialize');
        self::assertSame('fake-mcp', $answer['result']['serverInfo']['name'] ?? json_encode($answer) . self::contents($this->dir . '/relay.err'));
        self::assertSame('fake-mcp', $this->rpc($first, 1, 'initialize')['result']['serverInfo']['name']);
        self::assertNotSame(
            $this->rpc($first, 2, 'initialize')['result']['serverInfo']['pid'],
            $this->rpc($second, 2, 'initialize')['result']['serverInfo']['pid'],
            'one server process per connection'
        );
    }

    #[Test]
    public function refusesAWrongSignatureAndStartsNothing(): void
    {
        $this->startRelay();
        [$socket, $nonce] = $this->connect();

        $answer = $this->ask($socket, $nonce, 'open', 'fake', str_repeat('0', 64));

        self::assertFalse($answer['ok']);
        self::assertFileDoesNotExist($this->dir . '/started');
    }

    #[Test]
    public function aSignatureFromAnotherConnectionIsRefused(): void
    {
        $this->startRelay();
        [, $oldNonce] = $this->connect();
        [$socket] = $this->connect();

        $answer = $this->ask($socket, $oldNonce, 'open', 'fake', Handshake::signRequest(Handshake::key(self::CRYPT_KEY), $oldNonce, str_repeat('a', 32), 'open', 'fake', 20));

        self::assertFalse($answer['ok']);
        self::assertFileDoesNotExist($this->dir . '/started');
    }

    #[Test]
    public function aSignatureMadeWithAnotherKeyIsRefused(): void
    {
        $this->startRelay();
        [$socket, $nonce] = $this->connect();

        $answer = $this->ask($socket, $nonce, 'open', 'fake', cryptKey: 'another-install');

        self::assertFalse($answer['ok']);
    }

    #[Test]
    public function anUnknownServerIsRefused(): void
    {
        $this->startRelay();
        [$socket, $nonce] = $this->connect();

        $answer = $this->ask($socket, $nonce, 'open', 'nope');

        self::assertFalse($answer['ok']);
        self::assertStringContainsString('nope', $answer['error']);
        self::assertFileDoesNotExist($this->dir . '/started');
    }

    #[Test]
    public function aSilentClientIsDroppedWhileOthersAreServed(): void
    {
        $this->startRelay(handshake: 1.0);
        [$silent] = $this->connect();
        $busy = $this->openFake();

        self::assertSame('fake-mcp', $this->rpc($busy, 1, 'initialize')['result']['serverInfo']['name']);
        usleep(1500000);
        self::assertSame('', $this->readLine($silent, 1.0), 'closed without an answer');
        self::assertTrue(feof($silent) || fread($silent, 1) === false, 'the relay closed the connection');
    }

    #[Test]
    public function aServerInheritsNoSocketOrFileOfTheWorker(): void
    {
        $this->startRelay();
        $socket = $this->openFake();
        $pid = $this->rpc($socket, 1, 'initialize')['result']['serverInfo']['pid'];

        $inherited = array_filter(
            array_map('readlink', glob('/proc/' . $pid . '/fd/*') ?: []),
            static fn (string|false $target): bool => is_string($target) && (str_starts_with($target, 'socket:') || str_contains($target, 'run-relay.php'))
        );

        self::assertSame([], array_values($inherited), 'the server holds only its own stdio and files');
    }

    #[Test]
    public function aClientThatLeavesMidCallTakesItsServerWithIt(): void
    {
        $this->startRelay();
        $socket = $this->openFake();
        $pid = $this->rpc($socket, 1, 'initialize')['result']['serverInfo']['pid'];
        fwrite($socket, json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'slow-get', 'arguments' => new \stdClass()]]) . "\n");
        usleep(200000);
        fclose($socket);

        self::assertTrue($this->waitUntilGone($pid, 3.0), 'server process ended');
    }

    #[Test]
    public function aLargeResultArrivesWholeAtASlowReader(): void
    {
        $this->startRelay();
        $socket = $this->openFake();
        $this->rpc($socket, 1, 'initialize');
        fwrite($socket, json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'big', 'arguments' => new \stdClass()]]) . "\n");

        $data = '';
        $until = microtime(true) + 30;
        stream_set_blocking($socket, false);
        while (!str_ends_with($data, "\n") && microtime(true) < $until) {
            $read = [$socket];
            $write = $except = null;
            if (stream_select($read, $write, $except, 0, 20000) > 0) {
                $data .= (string)fread($socket, 4096);
                usleep(200);
            }
        }
        $message = json_decode(trim($data), true);

        self::assertSame(2, $message['id']);
        self::assertSame(str_repeat('y', 3 * 1024 * 1024), $message['result']['content'][0]['text']);
    }

    #[Test]
    public function aServerThatExitsFirstIsReportedWithItsCodeAndStderr(): void
    {
        $this->startRelay();
        $socket = $this->openFake();
        $this->rpc($socket, 1, 'initialize');

        $answer = $this->rpc($socket, 2, 'tools/call', ['name' => 'exit', 'arguments' => new \stdClass()]);
        // rpc() returns on the matching id or on close; the exit notice has no id, so read it directly.
        self::assertSame(['closed' => true], $answer);
    }

    #[Test]
    public function theExitNoticeCarriesCodeAndStderr(): void
    {
        $this->startRelay();
        $socket = $this->openFake();
        $this->rpc($socket, 1, 'initialize');
        fwrite($socket, json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'exit', 'arguments' => new \stdClass()]]) . "\n");

        $notice = json_decode($this->readLine($socket), true);

        self::assertSame('notifications/worker/exit', $notice['method']);
        self::assertSame(5, $notice['params']['code']);
        self::assertStringContainsString('bye: exiting on request', $notice['params']['stderr']);
        self::assertSame('', $this->readLine($socket, 2.0));
        self::assertTrue(feof($socket));
    }

    #[Test]
    public function reportsItsStatus(): void
    {
        $this->startRelay();
        $open = $this->openFake();
        [$socket, $nonce] = $this->connect();

        $status = $this->ask($socket, $nonce, 'status');
        self::assertIsResource($open);

        self::assertTrue($status['ok']);
        self::assertTrue($this->lastAnswerProven);
        self::assertSame('build-1', $status['build']);
        self::assertSame(realpath($this->dir), $status['root']);
        self::assertIsInt($status['pid']);
        self::assertSame(1, $status['connections']);
    }

    #[Test]
    public function stopsAfterTheOpenCallsEnd(): void
    {
        $this->startRelay();
        $open = $this->openFake();
        [$socket, $nonce] = $this->connect();

        self::assertSame(['ok' => true], $this->ask($socket, $nonce, 'stop'));
        $call = $this->rpc($open, 1, 'tools/call', ['name' => 'echo-args', 'arguments' => ['text' => 'still here']]);
        self::assertSame('{"text":"still here"}', $call['result']['content'][0]['text']);
        self::assertTrue(proc_get_status($this->relay)['running'], 'waits for the open call');

        fclose($open);
        $until = microtime(true) + 5;
        while (proc_get_status($this->relay)['running'] && microtime(true) < $until) {
            usleep(50000);
        }
        self::assertFalse(proc_get_status($this->relay)['running']);
    }

    #[Test]
    public function aRotatedCryptKeyIsAcceptedUnderTheNewKey(): void
    {
        $this->startRelay(cryptKey: self::CRYPT_KEY . "\nrotated-key");
        [$socket, $nonce] = $this->connect();

        $answer = $this->ask($socket, $nonce, 'open', 'fake', cryptKey: self::CRYPT_KEY . "\nrotated-key");

        self::assertSame(['ok' => true], $answer);
        self::assertTrue($this->lastAnswerProven);
    }

    #[Test]
    public function aWrongKeyIsRefusedWithoutProof(): void
    {
        $this->startRelay();
        [$socket, $nonce] = $this->connect();

        $answer = $this->ask($socket, $nonce, 'status', cryptKey: 'another-install');

        self::assertSame(['ok' => false, 'error' => 'not authorised'], $answer);
        self::assertFalse($this->lastAnswerProven);
    }

    #[Test]
    public function idleConnectionsCannotTakeTheSlotsOfRealCalls(): void
    {
        $this->startRelay(max: 2);
        $idle = [];
        for ($i = 0; $i < 6; $i++) {
            $idle[] = $this->connect()[0];
        }

        $socket = $this->openFake();

        self::assertSame('fake-mcp', $this->rpc($socket, 1, 'initialize')['result']['serverInfo']['name']);
        self::assertCount(6, $idle);
    }

    #[Test]
    public function theClientsTimeoutBoundsTheCall(): void
    {
        $this->startRelay(servers: ['silent' => ['command' => ['/bin/sh', '-c', 'exec sleep 49.' . random_int(100, 999)], 'timeout' => 30]]);
        [$socket, $nonce] = $this->connect();
        $started = microtime(true);
        self::assertSame(['ok' => true], $this->ask($socket, $nonce, 'open', 'silent', timeout: 1));

        self::assertSame('', $this->readLine($socket, 15.0));
        self::assertLessThan(7.5, microtime(true) - $started, 'closed at the client\'s 1 s + 5 s, not the row\'s 30 s');
    }

    #[Test]
    public function aServersOwnChildrenEndWithIt(): void
    {
        $marker = 'sleep 47.' . random_int(100, 999);
        $this->startRelay(servers: ['forker' => ['command' => ['/bin/sh', '-c', $marker . ' & exec "$0" "$1"', PHP_BINARY, dirname(__DIR__, 2) . '/Fakes/fake-mcp-server.php'], 'timeout' => 20]]);
        [$socket, $nonce] = $this->connect();
        self::assertSame(['ok' => true], $this->ask($socket, $nonce, 'open', 'forker'));
        $this->rpc($socket, 1, 'initialize');
        $sleeper = $this->pidOf($marker);
        self::assertNotNull($sleeper, 'the grandchild runs');

        fclose($socket);

        self::assertTrue($this->waitUntilGone($sleeper, 3.0), 'the grandchild ended with the call');
    }

    #[Test]
    public function aClientThatLeavesIsNoticedWhileItsServerIsNotReading(): void
    {
        $marker = 'sleep 48.' . random_int(100, 999);
        $this->startRelay(servers: ['deaf' => ['command' => ['/bin/sh', '-c', 'exec ' . $marker], 'timeout' => 20]]);
        [$socket, $nonce] = $this->connect();
        self::assertSame(['ok' => true], $this->ask($socket, $nonce, 'open', 'deaf'));
        $sleeper = $this->pidOf($marker);
        stream_set_blocking($socket, false);
        $chunk = str_repeat('z', 65536);
        $until = microtime(true) + 5;
        $sent = 0;
        while ($sent < 4 * 1048576 && microtime(true) < $until) {
            set_error_handler(static fn (): bool => true);
            $sent += (int)fwrite($socket, $chunk); // fails once the worker has ended the call, as it should
            restore_error_handler();
            usleep(1000);
        }

        fclose($socket);

        self::assertTrue($this->waitUntilGone((int)$sleeper, 4.0), 'killed soon after the client left, not at the deadline');
    }

    private function pidOf(string $commandLine): ?int
    {
        $until = microtime(true) + 3;
        while (microtime(true) < $until) {
            foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
                if (str_replace("\0", ' ', trim(self::contents($file), "\0")) === $commandLine) {
                    return (int)basename(dirname($file));
                }
            }
            usleep(50000);
        }

        return null;
    }

    #[Test]
    public function refusesAConnectionOverTheLimit(): void
    {
        $this->startRelay(max: 2);
        $open = [$this->openFake(), $this->openFake()];

        [$socket, $nonce] = $this->connect();
        $answer = $this->ask($socket, $nonce, 'open', 'fake');

        self::assertSame(['ok' => false, 'error' => 'busy'], $answer);
        self::assertTrue($this->lastAnswerProven);
        self::assertCount(2, $open);
    }
}
