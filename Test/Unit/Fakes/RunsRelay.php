<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

/**
 * Starts a real Relay (run-relay.php) in a child process for a test, and stops it afterwards.
 */
trait RunsRelay
{
    /** @var resource|null */
    private $relayProcess = null;
    private string $relayDir = '';

    /**
     * @param array<string, array{command: string[], env?: array<string,string>, timeout?: int}> $servers
     * @return string the relay's address
     */
    private function startRelay(array $servers, string $cryptKey): string
    {
        $this->relayDir = sys_get_temp_dir() . '/relay-' . bin2hex(random_bytes(4));
        mkdir($this->relayDir);
        $config = $this->relayDir . '/relay.json';
        file_put_contents($config, json_encode(['key' => $cryptKey, 'root' => $this->relayDir, 'servers' => $servers]));
        $pipes = [];
        $this->relayProcess = proc_open(
            [PHP_BINARY, __DIR__ . '/run-relay.php', $config],
            [1 => ['pipe', 'w'], 2 => ['file', $this->relayDir . '/relay.err', 'a']],
            $pipes
        );

        return trim((string)fgets($pipes[1]));
    }

    private function stopRelay(): void
    {
        if (is_resource($this->relayProcess)) {
            proc_terminate($this->relayProcess, 9);
            proc_close($this->relayProcess);
            $this->relayProcess = null;
        }
        if ($this->relayDir !== '' && is_dir($this->relayDir)) {
            array_map('unlink', glob($this->relayDir . '/*') ?: []);
            rmdir($this->relayDir);
        }
    }

    /**
     * @return string[]
     */
    private static function fakeServerCommand(): array
    {
        return [PHP_BINARY, __DIR__ . '/fake-mcp-server.php'];
    }
}
