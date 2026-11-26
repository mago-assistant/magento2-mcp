<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\OAuth;

use MagoAssistant\Mcp\Service\Url\EndpointRule;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * OAuth 2.1 as the MCP authorization spec uses it: protected resource metadata (RFC 9728), authorization
 * server metadata (RFC 8414), dynamic client registration (RFC 7591), PKCE and resource indicators (RFC 8707).
 *
 * Most URLs requested here are named by the MCP server's answers, not by the administrator. Which client
 * such a request goes through is decided in send(): a server on the public internet can only make this
 * install talk to its own origin and to other public hosts, never to a host inside the shop's network.
 */
class OAuthClient
{
    private const TIMEOUT = 15;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EndpointRule $endpointRule,
        private readonly HttpClientInterface $publicOnlyHttpClient
    ) {
    }

    /**
     * @return array<string, string> authorization_endpoint, token_endpoint, registration_endpoint, resource, scope
     * @throws OAuthException
     */
    public function discover(string $serverUrl): array
    {
        $resourceMetadata = $this->protectedResourceMetadata($serverUrl);
        // The resource the token is minted for is the MCP server the admin added, or that server's own
        // origin; metadata naming anything else would have the admin consent to a token for a different
        // resource at the same authorization server (RFC 9728 §3.3).
        $resource = $serverUrl;
        if (is_string($resourceMetadata['resource'] ?? null) && $resourceMetadata['resource'] !== '') {
            if (!self::sameResource($resourceMetadata['resource'], $serverUrl)) {
                throw new OAuthException(sprintf(
                    'The MCP server\'s metadata names another resource (%s) than the server itself; refusing to connect.',
                    $resourceMetadata['resource']
                ));
            }
            $resource = $resourceMetadata['resource'];
        }
        $issuer = $resourceMetadata['authorization_servers'][0] ?? null;
        if (!is_string($issuer) || $issuer === '') {
            throw new OAuthException('The MCP server does not name an authorization server.');
        }
        if ($this->endpointRule->error($issuer) !== null) {
            throw new OAuthException('The authorization server the MCP server names is not an https:// URL.');
        }

        $serverMetadata = $this->firstJson(
            $this->wellKnownUrls($issuer, ['oauth-authorization-server', 'openid-configuration']),
            $serverUrl
        );
        foreach (['authorization_endpoint', 'token_endpoint'] as $required) {
            if (!is_string($serverMetadata[$required] ?? null)) {
                throw new OAuthException(sprintf('The authorization server metadata has no %s.', $required));
            }
        }
        if (!in_array('S256', (array)($serverMetadata['code_challenge_methods_supported'] ?? ['S256']), true)) {
            throw new OAuthException('The authorization server does not support PKCE with S256.');
        }
        // Metadata that claims to be another issuer's is not this issuer's (RFC 8414 §3.3).
        if (is_string($serverMetadata['issuer'] ?? null)
            && rtrim($serverMetadata['issuer'], '/') !== rtrim($issuer, '/')
        ) {
            throw new OAuthException(sprintf(
                'The authorization server metadata names another issuer (%s) than %s; refusing to connect.',
                $serverMetadata['issuer'],
                $issuer
            ));
        }
        // The admin is sent to, and this install posts its codes to, these endpoints: https only, or
        // http on a local host, the rule every URL of this module follows.
        foreach (['authorization_endpoint', 'token_endpoint', 'registration_endpoint'] as $endpoint) {
            $url = $serverMetadata[$endpoint] ?? '';
            if (is_string($url) && $url !== '' && $this->endpointRule->error($url) !== null) {
                throw new OAuthException(sprintf('The authorization server\'s %s is not an https:// URL.', $endpoint));
            }
        }

        $scopes = $resourceMetadata['scopes_supported'] ?? $serverMetadata['scopes_supported'] ?? [];

        return [
            'authorization_endpoint' => $serverMetadata['authorization_endpoint'],
            'token_endpoint' => $serverMetadata['token_endpoint'],
            'registration_endpoint' => (string)($serverMetadata['registration_endpoint'] ?? ''),
            'resource' => $resource,
            'scope' => implode(' ', array_filter((array)$scopes, 'is_string')),
        ];
    }

    /**
     * Whether a declared resource is the server URL itself or a prefix of it on the same origin (a server
     * at https://host/mcp may declare https://host or https://host/mcp, never https://host/other).
     */
    private static function sameResource(string $declared, string $serverUrl): bool
    {
        $a = parse_url($declared);
        $b = parse_url($serverUrl);
        if (!is_array($a) || !is_array($b)) {
            return false;
        }
        $origin = static fn (array $p): string => strtolower((string)($p['scheme'] ?? '')) . '://'
            . strtolower((string)($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : '');
        if ($origin($a) !== $origin($b) || isset($a['query']) || isset($a['fragment'])) {
            return false;
        }
        $declaredPath = rtrim((string)($a['path'] ?? ''), '/');
        $serverPath = rtrim((string)($b['path'] ?? ''), '/');

        return $declaredPath === '' || $declaredPath === $serverPath || str_starts_with($serverPath, $declaredPath . '/');
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

        $response = $this->request('POST', $metadata['registration_endpoint'], $metadata['resource'] ?? '', ['json' => [
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

        $response = $this->request('POST', $metadata['token_endpoint'], $metadata['resource'] ?? '', ['body' => $body]);
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
            $response = $this->send('POST', $serverUrl, $serverUrl, [
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'],
                'body' => '{"jsonrpc":"2.0","id":1,"method":"ping"}',
            ]);
            $header = implode(', ', $response->getHeaders(false)['www-authenticate'] ?? []);
            // The hint is the server's word; one that is not an endpoint (plain http to a remote host,
            // another scheme) is not asked at all.
            if (preg_match('/resource_metadata="([^"]+)"/', $header, $match)
                && $this->endpointRule->error($match[1]) === null
            ) {
                $urls[] = $match[1];
            }
        } catch (HttpExceptionInterface $e) {
            throw new OAuthException('The MCP server is unreachable: ' . $e->getMessage(), 0, $e);
        }

        return $this->firstJson(
            [...$urls, ...$this->wellKnownUrls($serverUrl, ['oauth-protected-resource'])],
            $serverUrl
        );
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
     * @param string $serverUrl The MCP server these URLs were derived from
     * @return array<string, mixed>
     * @throws OAuthException
     */
    private function firstJson(array $urls, string $serverUrl): array
    {
        foreach ($urls as $url) {
            try {
                $response = $this->send('GET', $url, $serverUrl, ['headers' => ['Accept' => 'application/json']]);
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
     * @param string $serverUrl The MCP server (or its resource) the URL was discovered from
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws OAuthException
     */
    private function request(string $method, string $url, string $serverUrl, array $options): array
    {
        try {
            $response = $this->send($method, $url, $serverUrl, $options + ['headers' => ['Accept' => 'application/json']]);
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

    /**
     * The one place a request leaves this class.
     *
     * - A server the administrator placed on a local host (localhost, *.test, *.internal ...) is a local
     *   setup: it and whatever it names are asked directly.
     * - Any other server's own origin is asked directly, since the administrator chose that address, but
     *   without following redirects, which could lead anywhere.
     * - Every other host such a server names must be a public name (not a single label, not one of the
     *   local names) and is asked through the public-only client, which refuses an address in a private,
     *   loopback or link-local range when it connects and again on every redirect.
     *
     * @param string $method
     * @param string $url
     * @param string $serverUrl
     * @param array<string, mixed> $options
     * @throws HttpExceptionInterface
     */
    private function send(string $method, string $url, string $serverUrl, array $options): ResponseInterface
    {
        $options += ['timeout' => self::TIMEOUT];
        if ($this->endpointRule->isLocalUrl($serverUrl)) {
            return $this->httpClient->request($method, $url, $options);
        }
        if (self::origin($url) === self::origin($serverUrl)) {
            return $this->httpClient->request($method, $url, ['max_redirects' => 0] + $options);
        }

        // A name that only resolves inside the shop's network (a Docker service, a *.internal host) is
        // refused by name: the address check below cannot tell when that network uses a public range.
        $host = trim(strtolower((string)parse_url($url, PHP_URL_HOST)), '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) === false
            && (!str_contains($host, '.') || $this->endpointRule->isLocalUrl($url))
        ) {
            throw new TransportException(sprintf('Host "%s" is a local name; a remote MCP server may not name it.', $host));
        }

        return $this->publicOnlyHttpClient->request($method, $url, $options);
    }

    /**
     * scheme://host:port in lower case with the scheme's default port filled in; '' for a URL without a host.
     */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
        if ($scheme === '' || $host === '') {
            return '';
        }

        return $scheme . '://' . $host . ':' . ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));
    }
}
