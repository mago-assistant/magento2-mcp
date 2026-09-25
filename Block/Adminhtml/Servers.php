<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
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
}
