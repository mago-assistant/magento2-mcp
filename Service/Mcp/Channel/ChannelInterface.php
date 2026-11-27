<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Mcp\Channel;

use MagoAssistant\Mcp\Service\Mcp\McpProcessException;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;

/**
 * The byte streams of one local MCP server: its stdin to write to, its stdout and stderr to read from.
 *
 * StdioSession speaks JSON-RPC over a channel and does not know how the server was started: as a child
 * of this PHP process (ProcessChannel, the command line), or by the MCP worker (WorkerChannel, a web
 * request, where starting a process is not allowed).
 */
interface ChannelInterface
{
    /**
     * Start or reach the server.
     *
     * @throws McpProcessException when it cannot be started or reached
     */
    public function open(ServerConfig $server, string $rootDir, int $timeoutSeconds): void;

    /**
     * Write all bytes to the server's stdin; false when it no longer accepts input.
     */
    public function write(string $bytes): bool;

    /**
     * The server's stdout, non-blocking; null once closed.
     *
     * @return resource|null
     */
    public function output();

    /**
     * The server's stderr, non-blocking; null when this channel has none or once closed.
     *
     * @return resource|null
     */
    public function errors();

    /**
     * The server's exit code; null while it runs or when it cannot be known.
     */
    public function exitCode(): ?int;

    /**
     * Release the streams and end the server: at once with $kill, otherwise after a short grace period.
     */
    public function close(bool $kill): void;
}
