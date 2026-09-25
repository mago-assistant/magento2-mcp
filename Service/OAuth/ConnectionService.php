<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\OAuth;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use MagoAssistant\Mcp\Api\ServerInterface;
use MagoAssistant\Mcp\Service\ToolProvider;

/**
 * Connects the current admin user to an OAuth-protected MCP server (authorization code + PKCE).
 */
class ConnectionService
{
    private const PENDING_LIFETIME = 600;

    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly TokenRepository $tokenRepository,
        private readonly OAuthClient $oauthClient,
        private readonly ToolProvider $toolProvider,
        private readonly PendingAuthorizationStore $pendingStore,
        private readonly BackendUrl $backendUrl
    ) {
    }

    /**
     * @throws OAuthException
     */
    public function getServer(string $serverCode): ServerInterface
    {
        $server = $this->toolProvider->getServers()[$serverCode] ?? null;
        if ($server === null) {
            throw new OAuthException(sprintf('Unknown MCP server "%s".', $serverCode));
        }
        return $server;
    }

    /**
     * The authorization URL to send the admin user to
     *
     * @throws OAuthException
     */
    public function start(ServerInterface $server, int $adminUserId): string
    {
        $client = $this->registration($server);
        $verifier = $this->base64Url(random_bytes(48));
        $state = bin2hex(random_bytes(16));

        $pending = $this->pendingStore->all();
        $pending[$state] = [
            'server' => $server->getCode(),
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
    public function complete(string $state, string $code, int $adminUserId): ServerInterface
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
        $client = $this->clientRepository->find($server->getCode());
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
        $this->tokenRepository->save($adminUserId, $server->getCode(), $token);
        // Discovery runs for the logged-in user, who now has a token: fill the shared tool list.
        $this->toolProvider->getDefinition($server, true);

        return $server;
    }

    public function disconnect(ServerInterface $server, int $adminUserId): void
    {
        $this->tokenRepository->delete($adminUserId, $server->getCode());
    }

    public function isConnected(ServerInterface $server, int $adminUserId): bool
    {
        return $this->tokenRepository->find($adminUserId, $server->getCode()) !== null;
    }

    /**
     * Registers this install once per server; again when the server URL or the admin URL changed
     *
     * @return array{server_url: string, redirect_uri: string, client_id: string, client_secret: ?string, metadata: array<string, string>}
     * @throws OAuthException
     */
    private function registration(ServerInterface $server): array
    {
        $redirectUri = $this->backendUrl->getUrl('magomcp/oauth/callback', ['_nosecret' => true]);
        $client = $this->clientRepository->find($server->getCode());
        if ($client !== null
            && $client['server_url'] === $server->getUrl()
            && $client['redirect_uri'] === $redirectUri
        ) {
            return $client;
        }

        $metadata = $this->oauthClient->discover($server->getUrl());
        $host = (string)parse_url($redirectUri, PHP_URL_HOST);
        $registered = $this->oauthClient->register($metadata, $redirectUri, 'Mago Assistant (' . $host . ')');
        $client = [
            'server_url' => $server->getUrl(),
            'redirect_uri' => $redirectUri,
            'client_id' => $registered['client_id'],
            'client_secret' => $registered['client_secret'],
            'metadata' => $metadata,
        ];
        $this->clientRepository->save($server->getCode(), $client);

        return $client;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
