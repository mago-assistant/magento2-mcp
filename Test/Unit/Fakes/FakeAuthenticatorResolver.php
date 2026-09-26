<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use MagoAssistant\Mcp\Api\AuthenticatorInterface;
use MagoAssistant\Mcp\Service\Auth\AuthenticatorResolver;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;

/**
 * The real resolver for none and bearer; for an OAuth server an authenticator whose credentials are the
 * "server:userId" keys set in $credentials.
 */
final class FakeAuthenticatorResolver extends AuthenticatorResolver
{
    /** @var array<string,bool> "server:userId" => connected */
    public array $credentials = [];

    public function for(ServerConfig $server): AuthenticatorInterface
    {
        if ($server->authType !== ServerConfig::AUTH_OAUTH) {
            return parent::for($server);
        }
        $credentials = &$this->credentials;

        return new class ($server->name, $credentials) implements AuthenticatorInterface {
            /**
             * @param array<string,bool> $credentials
             */
            public function __construct(private readonly string $server, private array &$credentials)
            {
            }

            public function getHeaders(?int $adminUserId): array
            {
                return $this->hasCredentials($adminUserId) ? ['Authorization' => 'Bearer token-of-' . $adminUserId] : [];
            }

            public function onUnauthorized(?int $adminUserId): bool
            {
                return false;
            }

            public function hasCredentials(?int $adminUserId): bool
            {
                return $adminUserId !== null && ($this->credentials[$this->server . ':' . $adminUserId] ?? false);
            }
        };
    }
}
