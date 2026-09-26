<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Auth\Session as AuthSession;
use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use MagoAssistant\Mcp\Service\OAuth\ConnectionService;

class Servers extends Template
{
    public function __construct(
        Context $context,
        private readonly ServerRepositoryInterface $servers,
        private readonly ToolCatalog $catalog,
        private readonly ConnectionService $connections,
        private readonly AuthSession $authSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Rows plus 'tools_total'. Tool lists are read first so a fresh last_error written by a failed
     * fetch is in the rows that are returned.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getServers(): array
    {
        $counts = [];
        foreach ($this->servers->getEnabled() as $row) {
            $counts[$row['name']] = count($this->catalog->entriesForServer($row['name']));
        }
        $rows = [];
        foreach ($this->servers->getAll() as $row) {
            $row['tools_total'] = $counts[$row['name']] ?? 0;
            $rows[] = $row;
        }

        return $rows;
    }

    public function getToggleUrl(): string
    {
        return $this->getUrl('mago_mcp/servers/toggle');
    }

    public function getRescanUrl(): string
    {
        return $this->getUrl('mago_mcp/servers/rescan');
    }

    public function getRefreshUrl(): string
    {
        return $this->getUrl('mago_mcp/servers/refresh');
    }

    /**
     * Whether this row is an enabled OAuth server, the only kind with a connection to show.
     *
     * @param array<string,mixed> $row
     */
    public function hasConnection(array $row): bool
    {
        return (bool)$row['enabled']
            && ($row['transport'] ?? '') === ServerConfig::TRANSPORT_HTTP
            && ($row['auth_type'] ?? '') === ServerConfig::AUTH_OAUTH;
    }

    /**
     * @param array<string,mixed> $row
     */
    public function isConnected(array $row): bool
    {
        $userId = (int)$this->authSession->getUser()?->getId();

        return $this->hasConnection($row)
            && $userId > 0
            && $this->connections->isConnected(ServerConfig::fromRow($row, 1), $userId);
    }

    public function getConnectUrl(string $name): string
    {
        return $this->getUrl('mago_mcp/oauth/connect', ['name' => $name]);
    }

    public function getDisconnectUrl(string $name): string
    {
        return $this->getUrl('mago_mcp/oauth/disconnect', ['name' => $name]);
    }
}
