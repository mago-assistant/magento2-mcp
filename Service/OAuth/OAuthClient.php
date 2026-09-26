<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\OAuth;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OAuth 2.1 as the MCP authorization spec uses it: protected resource metadata (RFC 9728), authorization
 * server metadata (RFC 8414), dynamic client registration (RFC 7591), PKCE and resource indicators (RFC 8707).
 */
class OAuthClient
{
    private const TIMEOUT = 15;

    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {
    }

    /**
     * @return array<string, string> authorization_endpoint, token_endpoint, registration_endpoint, resource, scope
     * @throws OAuthException
     */
    public function discover(string $serverUrl): array
    {
        $resourceMetadata = $this->protectedResourceMetadata($serverUrl);
        $issuer = $resourceMetadata['authorization_servers'][0] ?? null;
        if (!is_string($issuer) || $issuer === '') {
            throw new OAuthException('The MCP server does not name an authorization server.');
        }

        $serverMetadata = $this->firstJson(
            $this->wellKnownUrls($issuer, ['oauth-authorization-server', 'openid-configuration'])
        );
        foreach (['authorization_endpoint', 'token_endpoint'] as $required) {
            if (!is_string($serverMetadata[$required] ?? null)) {
                throw new OAuthException(sprintf('The authorization server metadata has no %s.', $required));
            }
        }
        if (!in_array('S256', (array)($serverMetadata['code_challenge_methods_supported'] ?? ['S256']), true)) {
            throw new OAuthException('The authorization server does not support PKCE with S256.');
        }

        $scopes = $resourceMetadata['scopes_supported'] ?? $serverMetadata['scopes_supported'] ?? [];

        return [
            'authorization_endpoint' => $serverMetadata['authorization_endpoint'],
            'token_endpoint' => $serverMetadata['token_endpoint'],
            'registration_endpoint' => (string)($serverMetadata['registration_endpoint'] ?? ''),
            'resource' => is_string($resourceMetadata['resource'] ?? null) ? $resourceMetadata['resource'] : $serverUrl,
            'scope' => implode(' ', array_filter((array)$scopes, 'is_string')),
        ];
    }

    /**
     * @param array<string, string> $metadata As returned by discover()
     * @param string $redirectUri
     * @param string $clientName
     * @return array{client_id: string, client_secret: ?string}
     * @throws OAuthException
     */
    public function register(array $metadata, string $redirectUri, string $clientName): array
    {
        if (($metadata['registration_endpoint'] ?? '') === '') {
            throw new OAuthException('The authorization server does not support dynamic client registration.');
        }

        $response = $this->request('POST', $metadata['registration_endpoint'], ['json' => [
            'client_name' => $clientName,
            'redirect_uris' => [$redirectUri],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]]);
        if (!is_string($response['client_id'] ?? null)) {
            throw new OAuthException('The client registration returned no client_id.');
        }

        return [
            'client_id' => $response['client_id'],
            'client_secret' => is_string($response['client_secret'] ?? null) ? $response['client_secret'] : null,
        ];
    }

    /**
     * @param array<string, string> $metadata
     * @param array{client_id: string, client_secret: ?string} $client
     * @param string $redirectUri
     * @param string $codeChallenge
     * @param string $state
     */
    public function authorizationUrl(
        array $metadata,
        array $client,
        string $redirectUri,
        string $codeChallenge,
        string $state
    ): string {
        $query = array_filter([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
            'scope' => $metadata['scope'] ?? '',
            'resource' => $metadata['resource'] ?? '',
        ], static fn (string $value): bool => $value !== '');
        $endpoint = $metadata['authorization_endpoint'];

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($query);
    }

    /**
     * @param array<string, string> $metadata
     * @param array{client_id: string, client_secret: ?string} $client
     * @param string $code
     * @param string $codeVerifier
     * @param string $redirectUri
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int, scope: ?string}
     * @throws OAuthException
     */
    public function exchangeCode(
        array $metadata,
        array $client,
        string $code,
        string $codeVerifier,
        string $redirectUri
    ): array {
        return $this->token($metadata, $client, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * @param array<string, string> $metadata
     * @param array{client_id: string, client_secret: ?string} $client
     * @param string $refreshToken
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int, scope: ?string}
     * @throws OAuthException
     */
    public function refresh(array $metadata, array $client, string $refreshToken): array
    {
        $token = $this->token($metadata, $client, ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
        // Servers that do not rotate refresh tokens omit it; the old one stays valid.
        $token['refresh_token'] ??= $refreshToken;

        return $token;
    }

    /**
     * @param array<string, string> $metadata
     * @param array{client_id: string, client_secret: ?string} $client
     * @param array<string, string> $grant
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int, scope: ?string}
     * @throws OAuthException
     */
    private function token(array $metadata, array $client, array $grant): array
    {
        $body = $grant + ['client_id' => $client['client_id']];
        if ($client['client_secret'] !== null) {
            $body['client_secret'] = $client['client_secret'];
        }
        if (($metadata['resource'] ?? '') !== '') {
            $body['resource'] = $metadata['resource'];
        }

        $response = $this->request('POST', $metadata['token_endpoint'], ['body' => $body]);
        if (!is_string($response['access_token'] ?? null) || $response['access_token'] === '') {
            throw new OAuthException('The token endpoint returned no access token.');
        }

        return [
            'access_token' => $response['access_token'],
            'refresh_token' => is_string($response['refresh_token'] ?? null) ? $response['refresh_token'] : null,
            'expires_in' => is_numeric($response['expires_in'] ?? null) ? (int)$response['expires_in'] : null,
            'scope' => is_string($response['scope'] ?? null) ? $response['scope'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     * @throws OAuthException
     */
    private function protectedResourceMetadata(string $serverUrl): array
    {
        $urls = [];
        // An unauthenticated request makes the server point at its metadata in WWW-Authenticate.
        try {
            $response = $this->httpClient->request('POST', $serverUrl, [
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'],
                'body' => '{"jsonrpc":"2.0","id":1,"method":"ping"}',
                'timeout' => self::TIMEOUT,
            ]);
            $header = implode(', ', $response->getHeaders(false)['www-authenticate'] ?? []);
            if (preg_match('/resource_metadata="([^"]+)"/', $header, $match)) {
                $urls[] = $match[1];
            }
        } catch (HttpExceptionInterface $e) {
            throw new OAuthException('The MCP server is unreachable: ' . $e->getMessage(), 0, $e);
        }

        return $this->firstJson([...$urls, ...$this->wellKnownUrls($serverUrl, ['oauth-protected-resource'])]);
    }

    /**
     * RFC 8414 / 9728 well-known locations: path-specific first (/.well-known/<name>/<path>), then the root
     *
     * @param string $url
     * @param string[] $names
     * @return string[]
     */
    private function wellKnownUrls(string $url, array $names): array
    {
        $parts = parse_url($url);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = rtrim($parts['path'] ?? '', '/');

        $urls = [];
        foreach ($names as $name) {
            if ($path !== '') {
                $urls[] = $origin . '/.well-known/' . $name . $path;
            }
            $urls[] = $origin . '/.well-known/' . $name;
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param string[] $urls
     * @return array<string, mixed>
     * @throws OAuthException
     */
    private function firstJson(array $urls): array
    {
        foreach ($urls as $url) {
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => self::TIMEOUT,
                ]);
                if ($response->getStatusCode() !== 200) {
                    continue;
                }
                $decoded = json_decode($response->getContent(false), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (HttpExceptionInterface) {
                continue;
            }
        }

        throw new OAuthException('No OAuth metadata found at ' . implode(', ', $urls) . '.');
    }

    /**
     * @param string $method
     * @param string $url
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws OAuthException
     */
    private function request(string $method, string $url, array $options): array
    {
        try {
            $response = $this->httpClient->request($method, $url, $options + [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::TIMEOUT,
            ]);
            $status = $response->getStatusCode();
            $decoded = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface $e) {
            throw new OAuthException('The authorization server is unreachable: ' . $e->getMessage(), 0, $e);
        }

        if ($status < 200 || $status >= 300) {
            $error = is_array($decoded)
                ? trim(($decoded['error'] ?? '') . ' ' . ($decoded['error_description'] ?? ''))
                : '';
            // The status is the exception code, so a caller can tell a rejected grant (4xx) from an outage.
            throw new OAuthException(
                sprintf('The authorization server answered HTTP %d%s.', $status, $error !== '' ? ': ' . $error : ''),
                $status
            );
        }

        return is_array($decoded) ? $decoded : [];
    }
}
