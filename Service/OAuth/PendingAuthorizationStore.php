<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\OAuth;

use Magento\Framework\Session\SessionManagerInterface;

/**
 * Started authorizations (state => server, user, PKCE verifier) in the admin session
 */
class PendingAuthorizationStore
{
    private const SESSION_KEY = 'mago_mcp_oauth_pending';

    public function __construct(
        private readonly SessionManagerInterface $session
    ) {
    }

    /**
     * @return array<string, array{server: string, user: int, verifier: string, created: int}>
     */
    public function all(): array
    {
        $pending = $this->session->getData(self::SESSION_KEY);
        return is_array($pending) ? $pending : [];
    }

    /**
     * @param array<string, array{server: string, user: int, verifier: string, created: int}> $pending
     */
    public function replace(array $pending): void
    {
        $this->session->setData(self::SESSION_KEY, $pending);
    }
}
