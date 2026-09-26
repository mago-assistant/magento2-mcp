<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use MagoAssistant\Mcp\Service\Tool\Mcp\SkillRegistry;

/**
 * The addon's skill lookup failing outright (a missing table, a broken dependency).
 */
final class ThrowingSkillRegistry extends SkillRegistry
{
    public function __construct()
    {
    }

    public function all(?int $adminUserId = null): array
    {
        throw new \RuntimeException('down');
    }
}
