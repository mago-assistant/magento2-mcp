<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\OAuth;

use MagoAssistant\Mcp\Service\OAuth\OAuthClient;
use MagoAssistant\Mcp\Service\OAuth\OAuthException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OAuthClientTest extends TestCase
{
    private const METADATA = [
        'authorization_endpoint' => 'https://auth.example.com/oauth/authorize',
        'token_endpoint' => 'https://auth.example.com/oauth/token',
        'registration_endpoint' => 'https://auth.example.com/oauth/register',
        'resource' => 'https://mcp.example.com',
        'scope' => 'mcp:use',
    ];
    private const CLIENT = ['client_id' => 'client-1', 'client_secret' => null];

    /** @var array<int, array{method: string, url: string, body: string}> */
    private array $requests = [];

    #[Test]
    public function discoversEndpointsThroughTheResourceMetadataInWwwAuthenticate(): void
    {
        $client = $this->client([
            'POST https://mcp.example.com/mcp' => new MockResponse('{"message":"Unauthenticated."}', [
                'http_code' => 401,
                'response_headers' => ['www-authenticate: Bearer realm="mcp", resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource/mcp"'],
            ]),
            'GET https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => $this->json([
                'resource' => 'https://mcp.example.com',
                'authorization_servers' => ['https://auth.example.com'],
                'scopes_supported' => ['mcp:use'],
            ]),
            'GET https://auth.example.com/.well-known/oauth-authorization-server' => $this->json([
                'authorization_endpoint' => 'https://auth.example.com/oauth/authorize',
                'token_endpoint' => 'https://auth.example.com/oauth/token',
                'registration_endpoint' => 'https://auth.example.com/oauth/register',
                'code_challenge_methods_supported' => ['S256'],
            ]),
        ]);

        self::assertSame(self::METADATA, $client->discover('https://mcp.example.com/mcp'));
    }

    #[Test]
    public function fallsBackToWellKnownLocationsWithoutAHint(): void
    {
        $client = $this->client([
            'POST https://mcp.example.com/mcp' => new MockResponse('', ['http_code' => 401]),
            'GET https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => new MockResponse('', ['http_code' => 404]),
            'GET https://mcp.example.com/.well-known/oauth-protected-resource' => $this->json(['authorization_servers' => ['https://auth.example.com']]),
            'GET https://auth.example.com/.well-known/oauth-authorization-server' => new MockResponse('', ['http_code' => 404]),
            'GET https://auth.example.com/.well-known/openid-configuration' => $this->json([
                'authorization_endpoint' => 'https://auth.example.com/a',
                'token_endpoint' => 'https://auth.example.com/t',
            ]),
        ]);

        $metadata = $client->discover('https://mcp.example.com/mcp');

        self::assertSame('https://auth.example.com/t', $metadata['token_endpoint']);
        self::assertSame('https://mcp.example.com/mcp', $metadata['resource'], 'the server URL is the resource when none is declared');
    }

    #[Test]
    public function refusesAServerWithoutS256(): void
    {
        $client = $this->client([
            'POST https://mcp.example.com/mcp' => new MockResponse('', ['http_code' => 401]),
            'GET https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => $this->json(['authorization_servers' => ['https://auth.example.com']]),
            'GET https://auth.example.com/.well-known/oauth-authorization-server' => $this->json([
                'authorization_endpoint' => 'a', 'token_endpoint' => 't', 'code_challenge_methods_supported' => ['plain'],
            ]),
        ]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('S256');
        $client->discover('https://mcp.example.com/mcp');
    }

    #[Test]
    public function registersAPublicClient(): void
    {
        $client = $this->client(['POST https://auth.example.com/oauth/register' => $this->json(['client_id' => 'client-1'], 201)]);

        $registered = $client->register(self::METADATA, 'https://shop.test/admin/magomcp/oauth/callback/', 'Mago Assistant (shop.test)');

        self::assertSame(['client_id' => 'client-1', 'client_secret' => null], $registered);
        $body = json_decode($this->requests[0]['body'], true);
        self::assertSame(['https://shop.test/admin/magomcp/oauth/callback/'], $body['redirect_uris']);
        self::assertSame('none', $body['token_endpoint_auth_method']);
    }

    #[Test]
    public function buildsTheAuthorizationUrlWithPkceScopeAndResource(): void
    {
        $url = $this->client([])->authorizationUrl(self::METADATA, self::CLIENT, 'https://shop.test/cb', 'challenge', 'state-1');

        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        self::assertStringStartsWith('https://auth.example.com/oauth/authorize?', $url);
        self::assertSame([
            'response_type' => 'code',
            'client_id' => 'client-1',
            'redirect_uri' => 'https://shop.test/cb',
            'code_challenge' => 'challenge',
            'code_challenge_method' => 'S256',
            'state' => 'state-1',
            'scope' => 'mcp:use',
            'resource' => 'https://mcp.example.com',
        ], $query);
    }

    #[Test]
    public function exchangesTheCodeWithVerifierAndResource(): void
    {
        $client = $this->client(['POST https://auth.example.com/oauth/token' => $this->json([
            'access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600, 'scope' => 'mcp:use',
        ])]);

        $token = $client->exchangeCode(self::METADATA, self::CLIENT, 'code-1', 'verifier-1', 'https://shop.test/cb');

        self::assertSame(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600, 'scope' => 'mcp:use'], $token);
        parse_str($this->requests[0]['body'], $form);
        self::assertSame('authorization_code', $form['grant_type']);
        self::assertSame('verifier-1', $form['code_verifier']);
        self::assertSame('client-1', $form['client_id']);
        self::assertSame('https://mcp.example.com', $form['resource']);
        self::assertArrayNotHasKey('client_secret', $form);
    }

    #[Test]
    public function keepsTheRefreshTokenWhenTheServerDoesNotRotateIt(): void
    {
        $client = $this->client(['POST https://auth.example.com/oauth/token' => $this->json(['access_token' => 'at2', 'expires_in' => 60])]);

        self::assertSame('rt', $client->refresh(self::METADATA, self::CLIENT, 'rt')['refresh_token']);
    }

    #[Test]
    public function reportsTokenErrors(): void
    {
        $client = $this->client(['POST https://auth.example.com/oauth/token' => $this->json(
            ['error' => 'invalid_grant', 'error_description' => 'Code expired'],
            400
        )]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('HTTP 400: invalid_grant Code expired');
        $client->exchangeCode(self::METADATA, self::CLIENT, 'c', 'v', 'https://shop.test/cb');
    }

    /**
     * @param array<string, MockResponse> $routes "METHOD url" => response
     */
    private function client(array $routes): OAuthClient
    {
        return new OAuthClient(new MockHttpClient(function (string $method, string $url, array $options) use ($routes): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => (string)($options['body'] ?? '')];
            return $routes[$method . ' ' . $url] ?? new MockResponse('', ['http_code' => 404]);
        }));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data), ['http_code' => $status, 'response_headers' => ['content-type: application/json']]);
    }
}
