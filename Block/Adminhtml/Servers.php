<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Service\Catalog\CatalogEntry;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;

class Servers extends Template
{
    public function __construct(
        Context $context,
        private readonly ServerRepositoryInterface $servers,
        private readonly ToolCatalog $catalog,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Rows plus 'tools_enabled' and 'tools_total'. Tool lists are read first so a fresh last_error
     * written by a failed fetch is in the rows that are returned.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getServers(): array
    {
        $counts = [];
        foreach ($this->servers->getEnabled() as $row) {
            $entries = $this->catalog->entriesForServer($row['name']);
            $counts[$row['name']] = [
                count($entries),
                count(array_filter($entries, static fn (CatalogEntry $e): bool => $e->isCallable())),
            ];
        }
        $rows = [];
        foreach ($this->servers->getAll() as $row) {
            [$row['tools_total'], $row['tools_enabled']] = $counts[$row['name']] ?? [0, 0];
            $rows[] = $row;
        }

        return $rows;
    }

    public function getEditUrl(string $name): string
    {
        return $this->getUrl('mago_mcp/servers/edit', ['name' => $name]);
    }

    public function getNewUrl(): string
    {
        return $this->getUrl('mago_mcp/servers/edit');
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
}
