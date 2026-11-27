<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Mcp;

use MagoAssistant\Mago\Api\Config\RepositoryInterface as MagoConfig;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mcp\Service\Mcp\Channel\ChannelInterface;
use MagoAssistant\Mcp\Service\Privacy\SecretScrub;

// phpcs:disable Magento2.Functions.DiscouragedFunction

/**
 * One server, one deadline, newline-delimited JSON-RPC 2.0 over the streams of a channel.
 */
class StdioSession
{
    private const STDERR_CAP = 65536;
    /** One JSON-RPC message may not exceed this; a server that streams more is a fault, not a result. */
    public const MESSAGE_CAP = 8 * 1024 * 1024;
    /** What a diagnostic line costs in the debug log, masked and bounded. */
    private const LOG_LINE_CAP = 200;

    private bool $open = false;
    private bool $stderrClosed = false;
    private string $buffer = '';
    private string $stderr = '';
    private float $deadline = 0.0;
    private int $nextId = 0;
    private bool $timedOut = false;

    public function __construct(
        private readonly ChannelInterface $channel,
        private readonly ServerConfig $server,
        private readonly string $rootDir,
        private readonly int $timeoutSeconds,
        private readonly DebugLogger $debugLogger,
        private readonly MagoConfig $magoConfig,
        private readonly SecretScrub $secrets
    ) {
    }

    public function start(): void
    {
        $this->channel->open($this->server, $this->rootDir, $this->timeoutSeconds);
        $this->open = true;
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
        if (!$this->open) {
            return;
        }
        $this->channel->close($this->timedOut);
        $this->open = false;
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
        if (!$this->channel->write($line)) {
            throw new McpProcessException(sprintf('MCP server "%s" closed its input.', $this->server->name));
        }
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
                $this->debug('MCP non-JSON line', ['server' => $this->server->name, 'line' => $this->loggable($line)]);
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
        $out = $this->channel->output();
        $err = $this->stderrClosed ? null : $this->channel->errors();
        $read = array_values(array_filter([$out, $err], static fn ($stream): bool => $stream !== null));
        if ($read === []) {
            throw new McpProcessException(sprintf('Lost the streams of MCP server "%s".', $this->server->name));
        }
        $write = null;
        $except = null;
        $seconds = (int)floor($remaining);
        $ready = stream_select($read, $write, $except, $seconds, (int)(($remaining - $seconds) * 1e6));
        if ($ready === false) {
            throw new McpProcessException(sprintf('Lost the streams of MCP server "%s".', $this->server->name));
        }
        if ($ready === 0) {
            throw $this->timeout();
        }
        foreach ($read as $stream) {
            $chunk = fread($stream, 65536);
            if ($chunk === false || $chunk === '') {
                if (feof($stream) && $stream === $err) {
                    $this->stderrClosed = true; // a closed stderr would otherwise be "readable" forever
                }
                continue;
            }
            if ($stream === $err) {
                $this->stderr = substr($this->stderr . $chunk, 0, self::STDERR_CAP);
                $this->debug('MCP stderr', ['server' => $this->server->name, 'text' => $this->loggable($chunk)]);
                continue;
            }
            $this->buffer .= $chunk;
        }
        if (strlen($this->buffer) > self::MESSAGE_CAP && strpos($this->buffer, "\n") === false) {
            // Nothing after the cap is read; close() kills the process as after a timeout.
            $this->timedOut = true;
            $this->buffer = '';
            throw new McpProcessException(sprintf(
                'MCP server "%s" sent a message larger than %d MB.',
                $this->server->name,
                (int)(self::MESSAGE_CAP / 1048576)
            ));
        }
        if (strpos($this->buffer, "\n") === false && $out !== null && feof($out)) {
            $code = $this->channel->exitCode();
            $first = strtok($this->stderr, "\n") ?: 'no output';
            throw new McpProcessException(sprintf(
                'MCP server "%s" exited (code %s) before answering: %s',
                $this->server->name,
                $code === null ? '?' : (string)$code,
                $this->loggable($first)
            ));
        }
    }

    /**
     * A child's diagnostic line as it may appear in a log or an error: masked for credentials and cut
     * short, because a PHP warning can echo an argument and an exception a connection string.
     */
    private function loggable(string $text): string
    {
        $text = $this->secrets->mask(trim($text));

        return strlen($text) > self::LOG_LINE_CAP ? substr($text, 0, self::LOG_LINE_CAP) . '…' : $text;
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
