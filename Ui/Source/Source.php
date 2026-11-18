<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Ui\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;

class Source implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => DiscoveredServer::SOURCE_COMPOSER, 'label' => __('Composer')],
            ['value' => DiscoveredServer::SOURCE_MCP_JSON, 'label' => __('.mcp.json')],
            ['value' => DiscoveredServer::SOURCE_MODULE, 'label' => __('Module')],
            ['value' => DiscoveredServer::SOURCE_MANUAL, 'label' => __('Added here')],
        ];
    }
}
