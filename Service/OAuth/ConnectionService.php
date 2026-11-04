<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\OAuth;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;

/**
 * Connects an admin user to an OAuth-protected MCP server (authorization code + PKCE).
 */
class ConnectionService
{
    private const PENDING_LIFETIME = 600;

    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly TokenRepository $tokenRepository,
        private readonly OAuthClient $oauthClient,
        private readonly ToolCatalog $catalog,
        private readonly PendingAuthorizationStore $pendingStore,
        private readonly BackendUrl $backendUrl
    ) {
    }

    /**
     * An enabled http row with OAuth authentication; anything else throws
     *
     * @throws OAuthException
     */
    public function getServer(string $name): ServerConfig
    {
        $server = $this->catalog->serverConfig($name);
        if ($server === null
            || $server->transport !== ServerConfig::TRANSPORT_HTTP
            || $server->authType !== ServerConfig::AUTH_OAUTH
        ) {
            throw new OAuthException(sprintf('"%s" is not an enabled OAuth MCP server.', $name));
        }

        return $server;
    }

    /**
     * The authorization URL to send the admin user to
     *
     * @throws OAuthException
     */
    public function start(ServerConfig $server, int $adminUserId): string
    {
        $client = $this->registration($server);
        $verifier = $this->base64Url(random_bytes(48));
        $state = bin2hex(random_bytes(16));

        $pending = $this->pendingStore->all();
        $pending[$state] = [
            'server' => $server->name,
            'user' => $adminUserId,
            'verifier' => $verifier,
            'created' => time(),
        ];
        $this->pendingStore->replace($pending);

        return $this->oauthClient->authorizationUrl(
            $client['metadata'],
            $client,
            $client['redirect_uri'],
            $this->base64Url(hash('sha256', $verifier, true)),
            $state
        );
    }

    /**
     * @throws OAuthException
     */
    public function complete(string $state, string $code, int $adminUserId): ServerConfig
    {
        $all = $this->pendingStore->all();
        $pending = $all[$state] ?? null;
        // One-time state: a replayed or foreign callback finds nothing.
        unset($all[$state]);
        $this->pendingStore->replace($all);

        $isValid = $pending !== null
            && $pending['user'] === $adminUserId
            && $pending['created'] + self::PENDING_LIFETIME >= time();
        if (!$isValid) {
            throw new OAuthException(
                'The connection request expired or does not belong to this admin user. Please try again.'
            );
        }

        $server = $this->getServer($pending['server']);
        $client = $this->clientRepository->find($server->name);
        if ($client === null) {
            throw new OAuthException('The client registration is missing. Please try again.');
        }

        $token = $this->oauthClient->exchangeCode(
            $client['metadata'],
            $client,
            $code,
            $pending['verifier'],
            $client['redirect_uri']
        );
        $this->tokenRepository->save($adminUserId, $server->name, $token);
        // The connected admin's next request fetches the shared tool list with the new token.
        $this->catalog->refresh($server->name);

        return $server;
    }

    public function disconnect(ServerConfig $server, int $adminUserId): void
    {
        $this->tokenRepository->delete($adminUserId, $server->name);
    }

    public function isConnected(ServerConfig $server, int $adminUserId): bool
    {
        return $this->tokenRepository->find($adminUserId, $server->name) !== null;
    }

    /**
     * Registers this install once per server; again when the server URL or the admin URL changed
     *
     * @return array{server_url: string, redirect_uri: string, client_id: string, client_secret: ?string,
     *     metadata: array<string, string>}
     * @throws OAuthException
     */
    private function registration(ServerConfig $server): array
    {
        $redirectUri = $this->backendUrl->getUrl('mago_mcp/oauth/callback', ['_nosecret' => true]);
        $client = $this->clientRepository->find($server->name);
        if ($client !== null
            && $client['server_url'] === $server->url
            && $client['redirect_uri'] === $redirectUri
        ) {
            return $client;
        }

        $metadata = $this->oauthClient->discover($server->url);
        $host = (string)parse_url($redirectUri, PHP_URL_HOST);
        $registered = $this->oauthClient->register($metadata, $redirectUri, 'Mago Assistant (' . $host . ')');
        $client = [
            'server_url' => $server->url,
            'redirect_uri' => $redirectUri,
            'client_id' => $registered['client_id'],
            'client_secret' => $registered['client_secret'],
            'metadata' => $metadata,
        ];
        $this->clientRepository->save($server->name, $client);

        return $client;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
