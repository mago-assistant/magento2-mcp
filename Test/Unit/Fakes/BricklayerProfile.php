<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use MagoAssistant\Mcp\Service\Catalog\DefaultOverrides;

/**
 * The Bricklayer profile as etc/di.xml ships it, for tests that need the real defaults.
 */
final class BricklayerProfile
{
    public const DISABLED = ['code-runner', 'code-runner-help', 'batch-execute', 'reinitialize',
        'generate-module', 'generate-model', 'generate-controller', 'generate-api'];
    public const READ = ['database-query'];
    public const PERSONAL_DATA_PREFIXES = ['customer-', 'order-', 'invoice-', 'shipment-', 'creditmemo-'];

    public static function defaults(): DefaultOverrides
    {
        return new DefaultOverrides(
            ['bricklayer' => self::DISABLED],
            ['bricklayer' => self::READ],
            ['bricklayer' => self::PERSONAL_DATA_PREFIXES]
        );
    }
}
