<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service;

use MagoAssistant\Mcp\Api\AuthenticatorInterface;
use MagoAssistant\Mcp\Service\Client;
use MagoAssistant\Mcp\Service\McpException;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeMcpServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClientTest extends TestCase
{
    /** @var array<int, array{headers: array<string, string>, body: array<string, mixed>}> */
    private array $requests = [];

    #[Test]
    public function initializesOnceAndSendsSessionAndAuthHeaders(): void
    {
        $client = $this->client([
            $this->json(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18', 'instructions' => 'Be precise.']], ['mcp-session-id: sess-1']),
            new MockResponse('', ['http_code' => 202]),
            $this->json(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'a'], ['name' => 'b']]]]),
            $this->json(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['content' => []]]),
        ]);

        $listed = $client->listTools(new FakeMcpServer());
        $client->callTool(new FakeMcpServer(), 'a', []);

        self::assertSame(['a', 'b'], array_column($listed['tools'], 'name'));
        self::assertSame('Be precise.', $listed['instructions']);
        self::assertSame(['initialize', 'notifications/initialized', 'tools/list', 'tools/call'], array_column(array_column($this->requests, 'body'), 'method'));
        self::assertArrayNotHasKey('id', $this->requests[1]['body']);
        self::assertSame('Bearer secret', $this->requests[0]['headers']['authorization']);
        self::assertArrayNotHasKey('mcp-session-id', $this->requests[0]['headers']);
        self::assertSame('sess-1', $this->requests[3]['headers']['mcp-session-id']);
        self::assertSame('2025-06-18', $this->requests[3]['headers']['mcp-protocol-version']);
    }

    #[Test]
    public function sendsEmptyArgumentsAsJsonObject(): void
    {
        $client = $this->client([...$this->handshake(), $this->json(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => []]])]);

        $client->callTool(new FakeMcpServer(), 'who-am-i', []);

        self::assertSame('{"name":"who-am-i","arguments":{}}', json_encode($this->requests[2]['rawParams']));
    }

    #[Test]
    public function readsTheMatchingResponseFromAnEventStream(): void
    {
        $stream = "event: message\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\",\"params\":{}}\n\n"
            . "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":2,\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"ok\"}]}}\n\n";
        $client = $this->client([
            ...$this->handshake(),
            new MockResponse($stream, ['response_headers' => ['content-type: text/event-stream']]),
        ]);

        $result = $client->callTool(new FakeMcpServer(), 'x', ['a' => 1]);

        self::assertSame('ok', $result['content'][0]['text']);
    }

    #[Test]
    public function followsPagination(): void
    {
        $client = $this->client([
            ...$this->handshake(),
            $this->json(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'a']], 'nextCursor' => 'p2']]),
            $this->json(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['tools' => [['name' => 'b']]]]),
        ]);

        $listed = $client->listTools(new FakeMcpServer());

        self::assertSame(['a', 'b'], array_column($listed['tools'], 'name'));
        self::assertSame(['cursor' => 'p2'], $this->requests[3]['body']['params']);
    }

    #[Test]
    public function startsANewSessionWhenTheServerDroppedIt(): void
    {
        $client = $this->client([
            $this->json(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], ['mcp-session-id: old']),
            new MockResponse('', ['http_code' => 202]),
            new MockResponse('', ['http_code' => 404]),
            $this->json(['jsonrpc' => '2.0', 'id' => 3, 'result' => []], ['mcp-session-id: new']),
            new MockResponse('', ['http_code' => 202]),
            $this->json(['jsonrpc' => '2.0', 'id' => 4, 'result' => ['content' => []]]),
        ]);

        $client->callTool(new FakeMcpServer(), 'x', []);

        self::assertSame('new', $this->requests[5]['headers']['mcp-session-id']);
    }

    #[Test]
    public function retriesOnceAfterTheAuthenticatorRefreshedCredentials(): void
    {
        $authenticator = new class implements AuthenticatorInterface {
            public int $refreshes = 0;

            public function getHeaders(?int $adminUserId): array
            {
                return ['Authorization' => 'Bearer token-' . $this->refreshes];
            }

            public function onUnauthorized(?int $adminUserId): bool
            {
                $this->refreshes++;
                return true;
            }
        };
        $client = $this->client([
            new MockResponse('', ['http_code' => 401]),
            ...$this->handshake(),
            $this->json(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]]),
        ]);

        $client->listTools(new FakeMcpServer('test', $authenticator, [], []));

        self::assertSame('Bearer token-1', $this->requests[1]['headers']['authorization']);
    }

    #[Test]
    public function turnsRejectedCredentialsIntoAnException(): void
    {
        $client = $this->client([new MockResponse('{"message":"Unauthenticated."}', ['http_code' => 401])]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('rejected the credentials');

        $client->listTools(new FakeMcpServer());
    }

    #[Test]
    public function turnsJsonRpcErrorsIntoAnException(): void
    {
        $client = $this->client([
            ...$this->handshake(),
            $this->json(['jsonrpc' => '2.0', 'id' => 2, 'error' => ['code' => -32602, 'message' => 'Unknown tool']]),
        ]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Unknown tool');

        $client->callTool(new FakeMcpServer(), 'nope', []);
    }

    /**
     * @param MockResponse[] $responses
     */
    private function client(array $responses): Client
    {
        $queue = $responses;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$queue): MockResponse {
            $headers = [];
            foreach ($options['headers'] as $header) {
                [$name, $value] = explode(': ', $header, 2);
                $headers[strtolower($name)] = $value;
            }
            $raw = json_decode($options['body'], false);
            $this->requests[] = [
                'headers' => $headers,
                'body' => json_decode($options['body'], true),
                'rawParams' => $raw->params ?? null,
            ];
            return array_shift($queue) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new Client($http);
    }

    /**
     * @return MockResponse[]
     */
    private function handshake(): array
    {
        return [
            $this->json(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18']]),
            new MockResponse('', ['http_code' => 202]),
        ];
    }

    /**
     * @param array<string, mixed> $message
     * @param string[] $headers
     */
    private function json(array $message, array $headers = []): MockResponse
    {
        return new MockResponse(json_encode($message), ['response_headers' => ['content-type: application/json', ...$headers]]);
    }
}
