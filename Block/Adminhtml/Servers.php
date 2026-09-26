<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Service\Admin\ConnectionState;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;

class Servers extends Template
{
    public function __construct(
        Context $context,
        private readonly ServerRepositoryInterface $servers,
        private readonly ToolCatalog $catalog,
        private readonly ConnectionState $connectionState,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Rows plus 'tools_total'. Tool lists are read first so a fresh last_error written by a failed
     * fetch is in the rows that are returned. Counted for the viewing admin, so an OAuth server they
     * connected shows its tools at once.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getServers(): array
    {
        $counts = [];
        $adminUserId = $this->connectionState->currentAdminId();
        foreach ($this->servers->getEnabled() as $row) {
            $counts[$row['name']] = count($this->catalog->entriesForServer($row['name'], $adminUserId));
        }
        $rows = [];
        foreach ($this->servers->getAll() as $row) {
            $row['tools_total'] = $counts[$row['name']] ?? 0;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     */
    public function isManual(array $row): bool
    {
        return ($row['source'] ?? '') === DiscoveredServer::SOURCE_MANUAL;
    }

    /**
     * @param array<string,mixed> $row
     */
    public function hasConnection(array $row): bool
    {
        return $this->connectionState->hasConnection($row);
    }

    /**
     * @param array<string,mixed> $row
     */
    public function isConnected(array $row): bool
    {
        return $this->connectionState->isConnected($row);
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

    public function getNewUrl(): string
    {
        return $this->getUrl('mago_mcp/servers/new');
    }

    public function getEditUrl(string $name): string
    {
        return $this->getUrl('mago_mcp/servers/edit', ['name' => $name]);
    }

    public function getDeleteUrl(): string
    {
        return $this->getUrl('mago_mcp/servers/delete');
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
