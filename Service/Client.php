<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service;

use MagoAssistant\Mcp\Api\ServerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * JSON-RPC client for the MCP Streamable HTTP transport (spec 2025-06-18).
 */
class Client
{
    private const PROTOCOL_VERSION = '2025-06-18';
    private const MAX_PAGES = 10;

    private readonly HttpClientInterface $httpClient;

    /** @var array<string, array{session_id: ?string, protocol: string, instructions: string}> */
    private array $sessions = [];

    private int $requestId = 0;

    public function __construct(?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * @return array{tools: array<int, array<string, mixed>>, instructions: string}
     * @throws McpException
     */
    public function listTools(ServerInterface $server, ?int $adminUserId = null): array
    {
        $session = $this->session($server, $adminUserId);
        $tools = [];
        $cursor = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $this->call($server, 'tools/list', $cursor !== null ? ['cursor' => $cursor] : [], $adminUserId);
            foreach ($result['tools'] ?? [] as $tool) {
                if (is_array($tool) && is_string($tool['name'] ?? null)) {
                    $tools[] = $tool;
                }
            }
            $cursor = $result['nextCursor'] ?? null;
            if (!is_string($cursor) || $cursor === '') {
                break;
            }
        }

        return ['tools' => $tools, 'instructions' => $session['instructions']];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> The CallToolResult (content, structuredContent, isError)
     * @throws McpException
     */
    public function callTool(ServerInterface $server, string $name, array $arguments, ?int $adminUserId = null): array
    {
        // An empty PHP array encodes as [], but MCP requires arguments to be a JSON object.
        return $this->call(
            $server,
            'tools/call',
            ['name' => $name, 'arguments' => (object)$arguments],
            $adminUserId
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws McpException
     */
    private function call(ServerInterface $server, string $method, array $params, ?int $adminUserId): array
    {
        $this->session($server, $adminUserId);
        $response = $this->send($server, $method, $params, $adminUserId);
        if ($response['status'] === 404 && $this->sessions[$this->key($server, $adminUserId)]['session_id'] !== null) {
            // The server dropped our session: start a new one and retry once.
            unset($this->sessions[$this->key($server, $adminUserId)]);
            $this->session($server, $adminUserId);
            $response = $this->send($server, $method, $params, $adminUserId);
        }

        return $this->result($server, $method, $response);
    }

    /**
     * @return array{session_id: ?string, protocol: string, instructions: string}
     * @throws McpException
     */
    private function session(ServerInterface $server, ?int $adminUserId): array
    {
        $key = $this->key($server, $adminUserId);
        if (isset($this->sessions[$key])) {
            return $this->sessions[$key];
        }

        $response = $this->send($server, 'initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'mago-assistant', 'version' => '2'],
        ], $adminUserId);
        $result = $this->result($server, 'initialize', $response);

        $this->sessions[$key] = [
            'session_id' => $response['session_id'],
            'protocol' => is_string($result['protocolVersion'] ?? null)
                ? $result['protocolVersion']
                : self::PROTOCOL_VERSION,
            'instructions' => is_string($result['instructions'] ?? null) ? $result['instructions'] : '',
        ];
        $this->send($server, 'notifications/initialized', [], $adminUserId, true);

        return $this->sessions[$key];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{status: int, content_type: string, body: string, session_id: ?string, id: ?int}
     * @throws McpException
     */
    private function send(
        ServerInterface $server,
        string $method,
        array $params,
        ?int $adminUserId,
        bool $isNotification = false
    ): array {
        $payload = ['jsonrpc' => '2.0', 'method' => $method];
        $id = null;
        if (!$isNotification) {
            $id = ++$this->requestId;
            $payload['id'] = $id;
        }
        if ($params !== []) {
            $payload['params'] = $params;
        }
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new McpException('Could not encode the MCP request: ' . $e->getMessage(), 0, $e);
        }

        $authenticator = $server->getAuthenticator();
        $response = $this->post($server, $body, $adminUserId);
        if ($response['status'] === 401 && $authenticator->onUnauthorized($adminUserId)) {
            $response = $this->post($server, $body, $adminUserId);
        }
        if ($response['status'] === 401) {
            throw new McpAuthenticationException(sprintf(
                'MCP server "%s" rejected the credentials (401). Connect or reconnect it under Stores > '
                . 'Configuration > Mago Assistant > General.',
                $server->getLabel()
            ));
        }

        return $response + ['id' => $id];
    }

    /**
     * @return array{status: int, content_type: string, body: string, session_id: ?string}
     * @throws McpException
     */
    private function post(ServerInterface $server, string $body, ?int $adminUserId): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ];
        $session = $this->sessions[$this->key($server, $adminUserId)] ?? null;
        if ($session !== null) {
            $headers['MCP-Protocol-Version'] = $session['protocol'];
            if ($session['session_id'] !== null) {
                $headers['Mcp-Session-Id'] = $session['session_id'];
            }
        }
        $headers += $server->getAuthenticator()->getHeaders($adminUserId);

        try {
            $response = $this->httpClient->request('POST', $server->getUrl(), [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $server->getTimeout(),
                'max_duration' => $server->getTimeout(),
            ]);
            $status = $response->getStatusCode();
            $responseHeaders = $response->getHeaders(false);
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new McpException(
                sprintf('MCP server "%s" is unreachable: %s', $server->getLabel(), $e->getMessage()),
                0,
                $e
            );
        }

        return [
            'status' => $status,
            'content_type' => strtolower($responseHeaders['content-type'][0] ?? ''),
            'body' => $content,
            'session_id' => $responseHeaders['mcp-session-id'][0] ?? null,
        ];
    }

    /**
     * @param array{status: int, content_type: string, body: string, session_id: ?string, id: ?int} $response
     * @return array<string, mixed>
     * @throws McpException
     */
    private function result(ServerInterface $server, string $method, array $response): array
    {
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new McpException(sprintf(
                'MCP server "%s" answered %s with HTTP %d.',
                $server->getLabel(),
                $method,
                $response['status']
            ));
        }

        $message = $this->findResponse($response);
        if ($message === null) {
            throw new McpException(sprintf('MCP server "%s" sent no response to %s.', $server->getLabel(), $method));
        }
        if (isset($message['error'])) {
            $error = is_array($message['error']) ? (string)($message['error']['message'] ?? '') : '';
            $error = $error !== '' ? $error : 'unknown error';
            throw new McpException(sprintf('MCP server "%s": %s', $server->getLabel(), $error));
        }

        return is_array($message['result'] ?? null) ? $message['result'] : [];
    }

    /**
     * The JSON-RPC response to our request, from a plain JSON body or from an SSE stream (where it
     * may be preceded by server notifications)
     *
     * @param array{status: int, content_type: string, body: string, session_id: ?string, id: ?int} $response
     * @return array<string, mixed>|null
     */
    private function findResponse(array $response): ?array
    {
        $messages = [];
        if (str_starts_with($response['content_type'], 'text/event-stream')) {
            foreach (preg_split('/\r?\n\r?\n/', $response['body']) ?: [] as $event) {
                $data = [];
                foreach (preg_split('/\r?\n/', $event) ?: [] as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $data[] = ltrim(substr($line, 5), ' ');
                    }
                }
                if ($data !== []) {
                    $messages[] = json_decode(implode("\n", $data), true);
                }
            }
        } else {
            $decoded = json_decode($response['body'], true);
            $messages = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];
        }

        foreach ($messages as $message) {
            if (is_array($message) && ($message['id'] ?? null) === $response['id']
                && (isset($message['result']) || isset($message['error']))
            ) {
                return $message;
            }
        }

        return null;
    }

    private function key(ServerInterface $server, ?int $adminUserId): string
    {
        return $server->getCode() . ':' . ($adminUserId ?? 0);
    }
}
