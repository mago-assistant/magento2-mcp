<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use MagoAssistant\Mago\Service\Skills\PermissionChecker;

/**
 * Mago's permission checker without its database: reads and writes allowed as configured. Mago's
 * ToolRegistry takes the concrete class and only inspects a tool's schema when a checker is present.
 */
final class FakePermissionChecker extends PermissionChecker
{
    /** @var array<int,array{0:int,1:string,2:string}> */
    public array $asked = [];

    public function __construct(private readonly bool $allowWrite = true, private readonly bool $allowRead = true)
    {
    }

    public function isAllowed(int $adminUserId, string $skillName, string $action = 'read'): bool
    {
        $this->asked[] = [$adminUserId, $skillName, $action];

        return $action === 'read' ? $this->allowRead : $this->allowWrite;
    }
}
