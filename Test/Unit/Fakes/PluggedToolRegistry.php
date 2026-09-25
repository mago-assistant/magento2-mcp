<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Skills\PermissionChecker;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mcp\Plugin\ToolRegistryMcpSkills;

/**
 * Mago's ToolRegistry with the "after" plugins applied the way Magento's generated interceptor applies
 * them, so getToolDefinitions() and getTool(), which call the public methods on $this, see the MCP skills.
 */
final class PluggedToolRegistry extends ToolRegistry
{
    /**
     * @param PermissionChecker|null $permissionChecker
     * @param ToolInterface[] $tools
     * @param ToolRegistryMcpSkills $plugin
     */
    public function __construct(
        ?PermissionChecker $permissionChecker,
        array $tools,
        private readonly ToolRegistryMcpSkills $plugin
    ) {
        parent::__construct($permissionChecker, $tools);
    }

    public function getAllTools(): array
    {
        return $this->plugin->afterGetAllTools($this, parent::getAllTools());
    }

    public function getToolByName(string $name): ?ToolInterface
    {
        return $this->plugin->afterGetToolByName($this, parent::getToolByName($name), $name);
    }

    public function getEnabledTools(?int $adminUserId = null): array
    {
        return $this->plugin->afterGetEnabledTools($this, parent::getEnabledTools($adminUserId), $adminUserId);
    }
}
