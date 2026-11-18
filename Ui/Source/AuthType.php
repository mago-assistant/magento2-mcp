<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Ui\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;

class AuthType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => ServerConfig::AUTH_NONE, 'label' => __('None')],
            ['value' => ServerConfig::AUTH_BEARER, 'label' => __('Bearer token')],
            ['value' => ServerConfig::AUTH_OAUTH, 'label' => __('OAuth')],
        ];
    }
}
