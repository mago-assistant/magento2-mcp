<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Auth;

use MagoAssistant\Mcp\Api\AuthenticatorInterface;

/**
 * A static token shared by every admin user; an empty token sends no Authorization header.
 */
class BearerTokenAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly string $token = ''
    ) {
    }

    public function getHeaders(?int $adminUserId): array
    {
        return $this->token !== '' ? ['Authorization' => 'Bearer ' . $this->token] : [];
    }

    public function onUnauthorized(?int $adminUserId): bool
    {
        return false;
    }
}
