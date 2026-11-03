<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\OAuth;

class OAuthAuthenticatorFactory
{
    public function __construct(
        private readonly TokenRepository $tokenRepository,
        private readonly ClientRepository $clientRepository,
        private readonly OAuthClient $oauthClient,
        private readonly CurrentAdminUser $currentAdminUser
    ) {
    }

    public function create(string $serverCode): OAuthAuthenticator
    {
        return new OAuthAuthenticator(
            $serverCode,
            $this->tokenRepository,
            $this->clientRepository,
            $this->oauthClient,
            $this->currentAdminUser
        );
    }
}
