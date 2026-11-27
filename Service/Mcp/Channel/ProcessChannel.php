<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Mcp\Channel;

use MagoAssistant\Mcp\Service\Mcp\McpProcessException;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;

// phpcs:disable Magento2.Security.InsecureFunction, Magento2.Functions.DiscouragedFunction, Magento2.Exceptions.TryProcessSystemResources
// A JSON-RPC session over a child process needs proc_open and raw pipes: Magento\Framework\Shell only
// wraps exec() through a shell, with no stdin, no streaming stdout and no timeout. This is the one class
// that starts a process. It is used on the command line only (bin/magento, cron, the MCP worker); a
// web request reaches a local server through WorkerChannel. Every return value is checked.

/**
 * The server as a child process of this PHP process, started without a shell.
 */
class ProcessChannel implements ChannelInterface
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int,resource> */
    private array $pipes = [];

    private ?int $exitCode = null;

    public function open(ServerConfig $server, string $rootDir, int $timeoutSeconds): void
    {
        $cwd = $server->cwd ?? $rootDir;
        if (!str_starts_with($cwd, '/')) {
            $cwd = rtrim($rootDir, '/') . '/' . $cwd;
        }
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = array_merge(getenv(), $server->env);
        $pipes = [];
        // A missing binary makes proc_open() both return false and raise an E_WARNING (posix_spawn()
        // failing with ENOENT); the warning duplicates the McpProcessException thrown just below, so it
        // is caught here instead of leaking into the caller's error log or a PHPUnit warning.
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $process = proc_open($server->command, $spec, $pipes, $cwd, $env);
        } finally {
            restore_error_handler();
        }
        if (!is_resource($process)) {
            throw new McpProcessException(sprintf('Could not start MCP server "%s".', $server->name));
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $this->process = $process;
        $this->pipes = $pipes;
        $this->exitCode = null;
    }

    public function write(string $bytes): bool
    {
        if (!isset($this->pipes[0]) || !is_resource($this->pipes[0])) {
            return false;
        }
        $written = fwrite($this->pipes[0], $bytes);
        if ($written === false || $written < strlen($bytes)) {
            return false;
        }
        fflush($this->pipes[0]);

        return true;
    }

    public function output()
    {
        return isset($this->pipes[1]) && is_resource($this->pipes[1]) ? $this->pipes[1] : null;
    }

    public function errors()
    {
        return isset($this->pipes[2]) && is_resource($this->pipes[2]) ? $this->pipes[2] : null;
    }

    public function exitCode(): ?int
    {
        if ($this->exitCode !== null || !is_resource($this->process)) {
            return $this->exitCode;
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            return null;
        }
        // proc_get_status() reports the real code once; later calls say -1.
        $this->exitCode = (int)$status['exitcode'];

        return $this->exitCode;
    }

    public function close(bool $kill): void
    {
        if ($this->process === null) {
            return;
        }
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        if (!$kill) {
            $waitUntil = microtime(true) + 2.0;
            while (microtime(true) < $waitUntil && $this->exitCode() === null) {
                usleep(50000);
            }
        }
        if ($this->exitCode() === null) {
            proc_terminate($this->process, 9);
        }
        proc_close($this->process);
        $this->process = null;
    }
}
