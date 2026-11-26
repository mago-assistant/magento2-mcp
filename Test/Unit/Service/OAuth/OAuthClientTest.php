<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\OAuth;

use MagoAssistant\Mcp\Service\OAuth\OAuthClient;
use MagoAssistant\Mcp\Service\OAuth\OAuthException;
use MagoAssistant\Mcp\Service\Url\EndpointRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
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

    /** @var array<int, array{method: string, url: string, body: string, via: string, max_redirects: ?int}> */
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
    public function refusesMetadataNamingAnotherResource(): void
    {
        $client = $this->client([
            'POST https://mcp.example.com/mcp' => new MockResponse('', ['http_code' => 401]),
            'GET https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => $this->json([
                'resource' => 'https://api.example.com/payments',
                'authorization_servers' => ['https://auth.example.com'],
            ]),
        ]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('another resource');
        $client->discover('https://mcp.example.com/mcp');
    }

    #[Test]
    public function acceptsAResourceThatIsTheServersOwnOriginOrPath(): void
    {
        foreach (['https://mcp.example.com', 'https://mcp.example.com/', 'https://mcp.example.com/mcp', 'https://MCP.example.com/mcp/'] as $declared) {
            $client = $this->client([
                'POST https://mcp.example.com/mcp' => new MockResponse('', ['http_code' => 401]),
                'GET https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => $this->json([
                    'resource' => $declared, 'authorization_servers' => ['https://auth.example.com'],
                ]),
                'GET https://auth.example.com/.well-known/oauth-authorization-server' => $this->json([
                    'issuer' => 'https://auth.example.com',
                    'authorization_endpoint' => 'https://auth.example.com/a', 'token_endpoint' => 'https://auth.example.com/t',
                ]),
            ]);

            self::assertSame($declared, $client->discover('https://mcp.example.com/mcp')['resource'], $declared);
        }
    }

    #[Test]
    public function refusesAnIssuerMismatchAndPlainHttpEndpoints(): void
    {
        $resource = [
            'POST https://mcp.example.com/mcp' => new MockResponse('', ['http_code' => 401]),
            'GET https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => $this->json(['authorization_servers' => ['https://auth.example.com']]),
        ];
        $mismatch = $this->client($resource + ['GET https://auth.example.com/.well-known/oauth-authorization-server' => $this->json([
            'issuer' => 'https://evil.example.net',
            'authorization_endpoint' => 'https://auth.example.com/a', 'token_endpoint' => 'https://auth.example.com/t',
        ])]);
        try {
            $mismatch->discover('https://mcp.example.com/mcp');
            self::fail('expected an issuer refusal');
        } catch (OAuthException $e) {
            self::assertStringContainsString('another issuer', $e->getMessage());
        }

        $plain = $this->client($resource + ['GET https://auth.example.com/.well-known/oauth-authorization-server' => $this->json([
            'authorization_endpoint' => 'http://auth.example.com/a', 'token_endpoint' => 'https://auth.example.com/t',
        ])]);
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('not an https:// URL');
        $plain->discover('https://mcp.example.com/mcp');
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
    #[Test]
    public function aPublicServerReachesOtherHostsOnlyThroughThePublicOnlyClient(): void
    {
        $client = $this->client([
            'POST https://mcp.example.com/mcp' => new MockResponse('', [
                'http_code' => 401,
                'response_headers' => ['www-authenticate: Bearer resource_metadata="https://meta.example.net/prm"'],
            ]),
            'GET https://meta.example.net/prm' => $this->json(['authorization_servers' => ['https://auth.example.com']]),
            'GET https://auth.example.com/.well-known/oauth-authorization-server' => $this->json([
                'authorization_endpoint' => 'https://auth.example.com/a',
                'token_endpoint' => 'https://auth.example.com/t',
                'registration_endpoint' => 'https://auth.example.com/r',
            ]),
            'POST https://auth.example.com/r' => $this->json(['client_id' => 'c']),
            'POST https://auth.example.com/t' => $this->json(['access_token' => 'at']),
        ]);

        $metadata = $client->discover('https://mcp.example.com/mcp');
        $client->register($metadata, 'https://shop.example/callback', 'Shop');
        $client->exchangeCode($metadata, self::CLIENT, 'code', 'verifier', 'https://shop.example/callback');

        self::assertSame([
            'https://mcp.example.com/mcp' => 'plain',
            'https://meta.example.net/prm' => 'public-only',
            'https://auth.example.com/.well-known/oauth-authorization-server' => 'public-only',
            'https://auth.example.com/r' => 'public-only',
            'https://auth.example.com/t' => 'public-only',
        ], $this->via());
    }

    #[Test]
    public function aPublicServersOwnOriginIsAskedWithoutFollowingRedirects(): void
    {
        $client = $this->client([
            'POST https://mcp.example.com/mcp' => new MockResponse('', ['http_code' => 401]),
            'GET https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => $this->json([
                'authorization_servers' => ['https://mcp.example.com'],
            ]),
            'GET https://mcp.example.com/.well-known/oauth-authorization-server' => $this->json([
                'authorization_endpoint' => 'https://mcp.example.com/a',
                'token_endpoint' => 'https://mcp.example.com/t',
            ]),
        ]);

        $client->discover('https://mcp.example.com/mcp');

        self::assertSame(['plain'], array_values(array_unique(array_column($this->requests, 'via'))));
        self::assertSame([0], array_values(array_unique(array_column($this->requests, 'max_redirects'))));
    }

    #[Test]
    public function anotherPortOfThePublicServersHostIsAnotherHost(): void
    {
        $client = $this->client([
            'POST https://mcp.example.com/mcp' => new MockResponse('', [
                'http_code' => 401,
                'response_headers' => ['www-authenticate: Bearer resource_metadata="https://mcp.example.com:9200/prm"'],
            ]),
            'GET https://mcp.example.com:9200/prm' => $this->json(['authorization_servers' => ['https://auth.example.com']]),
            'GET https://auth.example.com/.well-known/oauth-authorization-server' => $this->json([
                'authorization_endpoint' => 'https://auth.example.com/a',
                'token_endpoint' => 'https://auth.example.com/t',
            ]),
        ]);

        $client->discover('https://mcp.example.com/mcp');

        self::assertSame('public-only', $this->via()['https://mcp.example.com:9200/prm']);
    }

    #[Test]
    public function aMetadataHintThatIsNotAnEndpointIsNeverRequested(): void
    {
        $client = $this->client([
            'POST https://mcp.example.com/mcp' => new MockResponse('', [
                'http_code' => 401,
                'response_headers' => ['www-authenticate: Bearer resource_metadata="http://169.254.169.254/latest/meta-data/"'],
            ]),
            'GET https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => $this->json([
                'authorization_servers' => ['https://auth.example.com'],
            ]),
            'GET https://auth.example.com/.well-known/oauth-authorization-server' => $this->json([
                'authorization_endpoint' => 'https://auth.example.com/a',
                'token_endpoint' => 'https://auth.example.com/t',
            ]),
        ]);

        $client->discover('https://mcp.example.com/mcp');

        self::assertArrayNotHasKey('http://169.254.169.254/latest/meta-data/', $this->via());
    }

    #[Test]
    public function aHintThePublicOnlyClientRefusesFallsBackToTheServersOwnWellKnownLocation(): void
    {
        $client = $this->client([
            'POST https://mcp.example.com/mcp' => new MockResponse('', [
                'http_code' => 401,
                'response_headers' => ['www-authenticate: Bearer resource_metadata="https://intranet.example.net/prm"'],
            ]),
            'GET https://intranet.example.net/prm' => new TransportException('Host "intranet.example.net" is blocked.'),
            'GET https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => $this->json([
                'authorization_servers' => ['https://auth.example.com'],
            ]),
            'GET https://auth.example.com/.well-known/oauth-authorization-server' => $this->json([
                'authorization_endpoint' => 'https://auth.example.com/a',
                'token_endpoint' => 'https://auth.example.com/t',
            ]),
        ]);

        self::assertSame('https://auth.example.com/t', $client->discover('https://mcp.example.com/mcp')['token_endpoint']);
    }

    #[Test]
    public function refusesAnAuthorizationServerThePublicOnlyClientRefuses(): void
    {
        $blocked = new TransportException('Host "auth.internal.example.net" is blocked.');
        $client = $this->client([
            'POST https://mcp.example.com/mcp' => new MockResponse('', ['http_code' => 401]),
            'GET https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => $this->json([
                'authorization_servers' => ['https://auth.internal.example.net'],
            ]),
            'GET https://auth.internal.example.net/.well-known/oauth-authorization-server' => $blocked,
            'GET https://auth.internal.example.net/.well-known/openid-configuration' => $blocked,
        ]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('No OAuth metadata found');
        $client->discover('https://mcp.example.com/mcp');
    }

    #[Test]
    public function refusesAnAuthorizationServerThatIsNotAnEndpoint(): void
    {
        $client = $this->client([
            'POST https://mcp.example.com/mcp' => new MockResponse('', ['http_code' => 401]),
            'GET https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => $this->json([
                'authorization_servers' => ['http://10.0.0.5:8500'],
            ]),
        ]);

        try {
            $client->discover('https://mcp.example.com/mcp');
            self::fail('Expected an OAuthException.');
        } catch (OAuthException $e) {
            self::assertStringContainsString('authorization server', $e->getMessage());
        }
        self::assertSame(
            [],
            array_filter(array_keys($this->via()), static fn (string $url): bool => str_contains($url, '10.0.0.5'))
        );
    }

    #[Test]
    public function aPublicServerMayNotNameALocalName(): void
    {
        $client = $this->client([
            'POST https://mcp.example.com/mcp' => new MockResponse('', ['http_code' => 401]),
            'GET https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => $this->json([
                'authorization_servers' => ['https://opensearch:9200'],
            ]),
            'GET https://opensearch:9200/.well-known/oauth-authorization-server' => $this->json([
                'authorization_endpoint' => 'https://opensearch:9200/a',
                'token_endpoint' => 'https://opensearch:9200/t',
            ]),
        ]);

        try {
            $client->discover('https://mcp.example.com/mcp');
            self::fail('Expected an OAuthException.');
        } catch (OAuthException) {
            self::assertSame(
                [],
                array_filter(array_keys($this->via()), static fn (string $url): bool => str_contains($url, 'opensearch'))
            );
        }
    }

    #[Test]
    public function aLocalServerMayNameLocalHosts(): void
    {
        $client = $this->client([
            'POST http://mcp.test/mcp' => new MockResponse('', ['http_code' => 401]),
            'GET http://mcp.test/.well-known/oauth-protected-resource/mcp' => $this->json([
                'authorization_servers' => ['http://auth.test'],
            ]),
            'GET http://auth.test/.well-known/oauth-authorization-server' => $this->json([
                'authorization_endpoint' => 'http://auth.test/a',
                'token_endpoint' => 'http://auth.test/t',
            ]),
            'POST http://auth.test/t' => $this->json(['access_token' => 'at']),
        ]);

        $metadata = $client->discover('http://mcp.test/mcp');
        $client->exchangeCode($metadata, self::CLIENT, 'code', 'verifier', 'http://shop.test/callback');

        self::assertSame(['plain'], array_values(array_unique(array_column($this->requests, 'via'))));
        self::assertSame([null], array_values(array_unique(array_column($this->requests, 'max_redirects'))));
    }

    private function client(array $routes): OAuthClient
    {
        return new OAuthClient(
            new MockHttpClient($this->recorder($routes, 'plain')),
            new EndpointRule(),
            new MockHttpClient($this->recorder($routes, 'public-only'))
        );
    }

    /**
     * @param array<string, MockResponse|\Throwable> $routes
     */
    private function recorder(array $routes, string $via): \Closure
    {
        return function (string $method, string $url, array $options) use ($routes, $via): MockResponse {
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => (string)($options['body'] ?? ''),
                'via' => $via,
                'max_redirects' => $options['max_redirects'] ?? null,
            ];
            $route = $routes[$method . ' ' . $url] ?? new MockResponse('', ['http_code' => 404]);
            if ($route instanceof \Throwable) {
                throw $route;
            }

            return $route;
        };
    }

    /**
     * @return array<string, string> url => the client it went through
     */
    private function via(): array
    {
        return array_column($this->requests, 'via', 'url');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data), ['http_code' => $status, 'response_headers' => ['content-type: application/json']]);
    }
}
