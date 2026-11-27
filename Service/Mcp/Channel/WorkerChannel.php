<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Mcp\Channel;

use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use MagoAssistant\Mcp\Service\Worker\WorkerClient;

// phpcs:disable Magento2.Functions.DiscouragedFunction

/**
 * The server as started by the MCP worker, reached over one socket. A web request uses this channel:
 * it names the server row and the worker, on the command line, starts its command.
 *
 * The socket carries the server's stdout only; its stderr and exit code reach the session as the
 * worker's last line (notifications/worker/exit).
 */
class WorkerChannel implements ChannelInterface
{
    private const WRITE_TIMEOUT = 10.0;

    /** @var resource|null */
    private $socket = null;

    public function __construct(private readonly WorkerClient $client)
    {
    }

    public function open(ServerConfig $server, string $rootDir, int $timeoutSeconds): void
    {
        $socket = $this->client->open($server->name, $timeoutSeconds);
        stream_set_blocking($socket, false);
        $this->socket = $socket;
    }

    public function write(string $bytes): bool
    {
        $until = microtime(true) + self::WRITE_TIMEOUT;
        while ($bytes !== '') {
            if (!is_resource($this->socket) || microtime(true) > $until) {
                return false;
            }
            $read = null;
            $write = [$this->socket];
            $except = null;
            if (stream_select($read, $write, $except, 0, 100000) < 1) {
                continue;
            }
            $written = fwrite($this->socket, $bytes);
            if ($written === false) {
                return false;
            }
            $bytes = (string)substr($bytes, $written);
        }

        return true;
    }

    public function output()
    {
        return is_resource($this->socket) ? $this->socket : null;
    }

    public function errors()
    {
        return null;
    }

    public function exitCode(): ?int
    {
        return null;
    }

    public function close(bool $kill): void
    {
        // Closing the connection is what ends the server: the worker kills it when its client leaves.
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }
}
