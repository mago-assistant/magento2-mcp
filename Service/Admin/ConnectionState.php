<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Admin;

use Magento\Backend\Model\Auth\Session as AuthSession;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use MagoAssistant\Mcp\Service\OAuth\ConnectionService;

/**
 * The logged-in admin's own OAuth connection state for a server row, shared by the grid and the edit page.
 */
class ConnectionState
{
    public function __construct(
        private readonly AuthSession $authSession,
        private readonly ConnectionService $connections
    ) {
    }

    public function currentAdminId(): ?int
    {
        $id = (int)$this->authSession->getUser()?->getId();

        return $id > 0 ? $id : null;
    }

    /**
     * Whether this row is an enabled OAuth server, the only kind with a connection to show.
     *
     * @param array<string,mixed> $row
     */
    public function hasConnection(array $row): bool
    {
        return (bool)($row['enabled'] ?? false)
            && ($row['transport'] ?? '') === ServerConfig::TRANSPORT_HTTP
            && ($row['auth_type'] ?? '') === ServerConfig::AUTH_OAUTH;
    }

    /**
     * @param array<string,mixed> $row
     */
    public function isConnected(array $row): bool
    {
        $adminId = $this->currentAdminId();

        return $adminId !== null
            && $this->hasConnection($row)
            && $this->connections->isConnected(ServerConfig::fromRow($row, 1), $adminId);
    }
}
