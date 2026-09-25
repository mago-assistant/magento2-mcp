<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Auth;

use MagoAssistant\Mcp\Api\AuthenticatorInterface;

/**
 * A server that needs no credentials: no headers, nothing to refresh, every admin has access.
 */
class NoneAuthenticator implements AuthenticatorInterface
{
    public function getHeaders(?int $adminUserId): array
    {
        return [];
    }

    public function onUnauthorized(?int $adminUserId): bool
    {
        return false;
    }

    public function hasCredentials(?int $adminUserId): bool
    {
        return true;
    }
}
