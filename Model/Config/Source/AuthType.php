<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class AuthType implements OptionSourceInterface
{
    public const BEARER = 'bearer';
    public const OAUTH = 'oauth';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::BEARER, 'label' => __('Bearer token (or none), shared by all admin users')],
            ['value' => self::OAUTH, 'label' => __('OAuth, each admin user connects their own account')],
        ];
    }
}
