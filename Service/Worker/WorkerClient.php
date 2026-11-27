<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Worker;

use MagoAssistant\Mcp\Service\Mcp\McpProcessException;

// phpcs:disable Magento2.Functions.DiscouragedFunction

/**
 * The web side of the MCP worker protocol: connect, read the nonce, send one signed request.
 * Uses sockets only, so it runs where proc_open and its siblings are disabled.
 */
class WorkerClient
{
    private const ANSWER_TIMEOUT = 10.0;

    /**
     * @param string $address host:port of the worker
     * @param string $key Handshake::key() of this install's crypt key
     */
    public function __construct(
        private readonly string $address,
        private readonly string $key,
        private readonly float $connectTimeout = 5.0
    ) {
    }

    /**
     * Ask the worker to start a local server; afterwards the socket is that server's stdin and stdout.
     *
     * @return resource
     * @throws McpProcessException when the worker does not answer or refuses
     */
    public function open(string $server, int $timeoutSeconds)
    {
        [$socket, $answer] = $this->request('open', $server, $timeoutSeconds);
        if (($answer['ok'] ?? false) !== true) {
            fclose($socket);
            throw new McpProcessException(sprintf(
                'The MCP worker did not start "%s": %s',
                $server,
                is_string($answer['error'] ?? null) ? $answer['error'] : 'no reason given'
            ));
        }

        return $socket;
    }

    /**
     * @return array<string,mixed>|null the worker's status, or null when nothing answers
     */
    public function status(): ?array
    {
        try {
            [$socket, $answer] = $this->request('status', '', 0);
        } catch (McpProcessException) {
            return null;
        }
        fclose($socket);

        return ($answer['ok'] ?? false) === true ? $answer : null;
    }

    public function stop(): bool
    {
        try {
            [$socket, $answer] = $this->request('stop', '', 0);
        } catch (McpProcessException) {
            return false;
        }
        fclose($socket);

        return ($answer['ok'] ?? false) === true;
    }

    public function address(): string
    {
        return $this->address;
    }

    /**
     * @return array{0: resource, 1: array<string,mixed>} the open socket and the worker's answer
     * @throws McpProcessException
     */
    private function request(string $op, string $server, int $timeoutSeconds): array
    {
        $errno = 0;
        $error = '';
        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client('tcp://' . $this->address, $errno, $error, $this->connectTimeout);
        } finally {
            restore_error_handler();
        }
        if (!is_resource($socket)) {
            throw new WorkerUnavailableException($this->address);
        }
        $hello = json_decode($this->readLine($socket), true);
        if (!is_array($hello) || ($hello['v'] ?? null) !== Handshake::PROTOCOL_VERSION || !is_string($hello['nonce'] ?? null)) {
            fclose($socket);
            if (is_array($hello) && ($hello['error'] ?? null) === 'busy') {
                throw new McpProcessException('The MCP worker is busy; try again in a moment.');
            }
            throw new WorkerUnavailableException($this->address);
        }
        $line = json_encode([
            'op' => $op,
            'server' => $server,
            'timeout' => $timeoutSeconds,
            'auth' => Handshake::sign($this->key, $hello['nonce'], $op, $server),
        ]) . "\n";
        if (fwrite($socket, $line) !== strlen($line)) {
            fclose($socket);
            throw new WorkerUnavailableException($this->address);
        }
        $answer = json_decode($this->readLine($socket), true);
        if (!is_array($answer)) {
            fclose($socket);
            throw new McpProcessException('The MCP worker closed the connection without answering.');
        }

        return [$socket, $answer];
    }

    /**
     * One line, read byte by byte so nothing after it (the server's first bytes) is consumed here.
     *
     * @param resource $socket
     */
    private function readLine($socket): string
    {
        $until = microtime(true) + self::ANSWER_TIMEOUT;
        $line = '';
        stream_set_blocking($socket, false);
        while (!str_ends_with($line, "\n") && microtime(true) < $until && strlen($line) < 8192) {
            $read = [$socket];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, 100000) < 1) {
                continue;
            }
            $byte = fread($socket, 1);
            if ($byte === false || $byte === '') {
                if (feof($socket)) {
                    break;
                }
                continue;
            }
            $line .= $byte;
        }

        return trim($line);
    }
}
