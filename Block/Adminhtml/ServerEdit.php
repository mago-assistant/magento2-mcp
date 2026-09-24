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
use MagoAssistant\Mcp\Service\Catalog\DefaultOverrides;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;

class ServerEdit extends Template
{
    public function __construct(
        Context $context,
        private readonly ServerRepositoryInterface $servers,
        private readonly ToolCatalog $catalog,
        private readonly DefaultOverrides $defaults,
        private readonly ModeClassifier $classifier,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<string,mixed>|null null for the "new manual server" form
     */
    public function getServer(): ?array
    {
        $name = (string)$this->getRequest()->getParam('name', '');

        return $name === '' ? null : $this->servers->getByName($name);
    }

    public function isManual(): bool
    {
        $server = $this->getServer();

        return $server === null || $server['source'] === DiscoveredServer::SOURCE_MANUAL;
    }

    /**
     * @return CatalogEntry[]
     */
    public function getTools(): array
    {
        $server = $this->getServer();

        return $server === null ? [] : $this->catalog->entriesForServer($server['name']);
    }

    /**
     * The mode a tool would get without an admin override, for the "Automatic" option label.
     */
    public function automaticMode(CatalogEntry $tool): string
    {
        return $this->defaults->modeFor($tool->server, $tool->tool) ?? $this->classifier->classify($tool->tool);
    }

    public function hasPersonalDataTools(): bool
    {
        foreach ($this->getTools() as $tool) {
            if ($tool->personalData) {
                return true;
            }
        }

        return false;
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('mago_mcp/servers/save');
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('mago_mcp/servers/index');
    }

    /**
     * @param array<string,string> $env
     */
    public function envAsText(array $env): string
    {
        $lines = [];
        foreach ($env as $key => $value) {
            $lines[] = $key . '=' . $value;
        }

        return implode("\n", $lines);
    }
}
