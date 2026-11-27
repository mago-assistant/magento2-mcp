<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Worker;

use MagoAssistant\Mcp\Service\Mcp\McpProcessException;

// phpcs:disable Magento2.Functions.DiscouragedFunction

/**
 * The web side of the MCP worker protocol: connect, read the worker's nonce, send one signed request, and
 * check the worker's signed answer before anything else is sent. Uses sockets only, so it runs where
 * proc_open and its siblings are disabled.
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
     * @throws McpProcessException when the worker does not answer, cannot prove itself or refuses
     */
    public function open(string $server, int $timeoutSeconds)
    {
        [$socket, $answer, $proven] = $this->request('open', $server, $timeoutSeconds);
        if (!$proven) {
            fclose($socket);
            throw new McpProcessException(sprintf(
                'The process at %s could not prove it is this shop\'s MCP worker (%s); nothing was sent to it.',
                $this->address,
                is_string($answer['error'] ?? null) ? $answer['error'] : 'no valid proof'
            ));
        }
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
     * null: nothing answers. ['state' => 'busy']: the worker has no free slot. ['state' => 'refused']:
     * something answers but cannot prove it is this install's worker (another crypt key, an older worker,
     * another program). ['state' => 'running', 'root', 'build', 'pid', 'connections', 'started'] otherwise.
     *
     * @return array<string,mixed>|null
     */
    public function status(): ?array
    {
        try {
            [$socket, $answer, $proven] = $this->request('status', '', 0);
        } catch (WorkerUnavailableException) {
            return null;
        } catch (McpProcessException $e) {
            return str_contains($e->getMessage(), 'busy') ? ['state' => 'busy'] : ['state' => 'refused'];
        }
        fclose($socket);
        if (!$proven || ($answer['ok'] ?? false) !== true) {
            return ($answer['error'] ?? null) === 'busy' && $proven ? ['state' => 'busy'] : ['state' => 'refused'];
        }
        unset($answer['ok'], $answer['proof']);

        return ['state' => 'running'] + $answer;
    }

    /**
     * Ask a worker of this install to stop; false when none answers or it cannot prove itself.
     */
    public function stop(): bool
    {
        try {
            [$socket, $answer, $proven] = $this->request('stop', '', 0);
        } catch (McpProcessException) {
            return false;
        }
        fclose($socket);

        return $proven && ($answer['ok'] ?? false) === true;
    }

    public function address(): string
    {
        return $this->address;
    }

    /**
     * @return array{0: resource, 1: array<string,mixed>, 2: bool} the open socket, the answer, whether it is proven
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
        if (is_array($hello) && ($hello['error'] ?? null) === 'busy') {
            fclose($socket);
            throw new McpProcessException('The MCP worker is busy; try again in a moment.');
        }
        if (!is_array($hello) || ($hello['v'] ?? null) !== Handshake::PROTOCOL_VERSION || !Handshake::isNonce($hello['nonce'] ?? null)) {
            fclose($socket);
            throw new McpProcessException(sprintf(
                'The process at %s does not speak this version of the MCP worker protocol; restart the worker '
                . '(bin/magento mago:mcp:serve --stop, cron starts the current one).',
                $this->address
            ));
        }
        $clientNonce = Handshake::nonce();
        $line = json_encode([
            'op' => $op,
            'server' => $server,
            'timeout' => $timeoutSeconds,
            'cnonce' => $clientNonce,
            'auth' => Handshake::signRequest($this->key, $hello['nonce'], $clientNonce, $op, $server, $timeoutSeconds),
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

        return [$socket, $answer, Handshake::verifyAnswer($this->key, $hello['nonce'], $clientNonce, $answer)];
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
        while (!str_ends_with($line, "\n") && microtime(true) < $until && strlen($line) < 65536) {
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
