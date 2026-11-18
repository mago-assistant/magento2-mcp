<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Ui\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;

class Transport implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => ServerConfig::TRANSPORT_STDIO, 'label' => __('stdio')],
            ['value' => ServerConfig::TRANSPORT_HTTP, 'label' => __('http')],
        ];
    }
}
