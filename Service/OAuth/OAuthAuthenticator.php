<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\OAuth;

use MagoAssistant\Mcp\Api\AuthenticatorInterface;

/**
 * Sends the OAuth token of the admin who makes the request, never another admin's; with no admin id
 * there are no credentials. Nothing here reads the admin session.
 */
class OAuthAuthenticator implements AuthenticatorInterface
{
    // Refresh this many seconds before expiry so a token does not run out mid-conversation.
    private const EXPIRY_MARGIN = 60;

    public function __construct(
        private readonly string $serverCode,
        private readonly TokenRepository $tokenRepository,
        private readonly ClientRepository $clientRepository,
        private readonly OAuthClient $oauthClient
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
     * A refresh the authorization server rejects (a 4xx such as invalid_grant) disconnects the user, so they
     * are asked to connect again instead of getting 401s. An outage (5xx, unreachable) keeps the connection:
     * this attempt sends no token, the next one tries again.
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
        } catch (OAuthException $e) {
            if ($e->getCode() >= 400 && $e->getCode() < 500) {
                $this->tokenRepository->delete($userId, $this->serverCode);
            }
            return null;
        }
        $this->tokenRepository->save($userId, $this->serverCode, $token);

        return $token;
    }

    /**
     * Explicit only: a missing or non-positive id is no user, whatever the session says.
     */
    private function userId(?int $adminUserId): ?int
    {
        return $adminUserId !== null && $adminUserId > 0 ? $adminUserId : null;
    }
}
