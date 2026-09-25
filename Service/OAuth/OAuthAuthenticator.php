<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\OAuth;

use MagoAssistant\Mcp\Api\AuthenticatorInterface;

/**
 * Sends the OAuth token of the admin user who makes the request; never another user's token.
 */
class OAuthAuthenticator implements AuthenticatorInterface
{
    // Refresh this many seconds before expiry so a token does not run out mid-conversation.
    private const EXPIRY_MARGIN = 60;

    public function __construct(
        private readonly string $serverCode,
        private readonly TokenRepository $tokenRepository,
        private readonly ClientRepository $clientRepository,
        private readonly OAuthClient $oauthClient,
        private readonly CurrentAdminUser $currentAdminUser
    ) {
    }

    public function getHeaders(?int $adminUserId): array
    {
        $userId = $this->userId($adminUserId);
        $token = $userId !== null ? $this->tokenRepository->find($userId, $this->serverCode) : null;
        if ($userId === null || $token === null) {
            return [];
        }

        if ($token['expires_at'] !== null && $token['expires_at'] - self::EXPIRY_MARGIN < time()) {
            $token = $this->refresh($userId, $token['refresh_token']);
            if ($token === null) {
                return [];
            }
        }

        return ['Authorization' => 'Bearer ' . $token['access_token']];
    }

    public function onUnauthorized(?int $adminUserId): bool
    {
        $userId = $this->userId($adminUserId);
        $token = $userId !== null ? $this->tokenRepository->find($userId, $this->serverCode) : null;

        return $userId !== null && $token !== null && $this->refresh($userId, $token['refresh_token']) !== null;
    }

    public function hasCredentials(?int $adminUserId): bool
    {
        $userId = $this->userId($adminUserId);

        return $userId !== null && $this->tokenRepository->find($userId, $this->serverCode) !== null;
    }

    /**
     * A failed refresh disconnects the user, so they are asked to connect again instead of getting 401s.
     *
     * @return array{access_token: string}|null
     */
    private function refresh(int $userId, ?string $refreshToken): ?array
    {
        $client = $this->clientRepository->find($this->serverCode);
        if ($refreshToken === null || $client === null) {
            $this->tokenRepository->delete($userId, $this->serverCode);
            return null;
        }

        try {
            $token = $this->oauthClient->refresh($client['metadata'], $client, $refreshToken);
        } catch (OAuthException) {
            $this->tokenRepository->delete($userId, $this->serverCode);
            return null;
        }
        $this->tokenRepository->save($userId, $this->serverCode, $token);

        return $token;
    }

    /**
     * Tool discovery has no user in its call chain; in the admin it runs for the logged-in user.
     */
    private function userId(?int $adminUserId): ?int
    {
        if ($adminUserId !== null && $adminUserId > 0) {
            return $adminUserId;
        }
        return $this->currentAdminUser->getId();
    }
}
