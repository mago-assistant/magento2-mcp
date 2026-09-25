<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Plugin;

use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mcp\Service\ToolProvider;

/**
 * Adds the MCP tools to Mago's registry. getTool() and getToolDefinitions() call these methods on
 * $this, which is the interceptor, so they see the MCP tools too.
 */
class AddMcpToolsToRegistry
{
    public function __construct(
        private readonly ToolProvider $toolProvider
    ) {
    }

    /**
     * @param ToolRegistry $subject
     * @param ToolInterface[] $result
     * @return ToolInterface[]
     */
    public function afterGetAllTools(ToolRegistry $subject, array $result): array
    {
        foreach ($this->toolProvider->getTools() as $tool) {
            // A provided tool never replaces a built-in one of the same name.
            $result[$tool->getName()] ??= $tool;
        }
        return $result;
    }

    /**
     * @param ToolRegistry $subject
     * @param ToolInterface[] $result
     * @param int|null $adminUserId
     * @return ToolInterface[]
     */
    public function afterGetEnabledTools(ToolRegistry $subject, array $result, ?int $adminUserId = null): array
    {
        foreach ($this->toolProvider->getTools() as $tool) {
            if (!isset($result[$tool->getName()]) && $this->isAvailable($subject, $tool, $adminUserId)) {
                $result[$tool->getName()] = $tool;
            }
        }
        return $result;
    }

    public function afterGetToolByName(ToolRegistry $subject, ?ToolInterface $result, string $name): ?ToolInterface
    {
        if ($result !== null) {
            return $result;
        }
        foreach ($this->toolProvider->getTools() as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }
        return null;
    }

    /**
     * Mirrors the registry's private availability check: a read grant is enough when the tool has
     * a read-only action, otherwise a write grant is needed.
     */
    private function isAvailable(ToolRegistry $registry, ToolInterface $tool, ?int $adminUserId): bool
    {
        foreach ($tool->getParameterSchema()['properties']['action']['enum'] ?? [] as $action) {
            if ($tool->isReadOnlyAction(['action' => $action])) {
                return $registry->isCallAllowed($tool, ['action' => $action], $adminUserId);
            }
        }
        return $registry->hasWriteAccess($tool, $adminUserId);
    }
}
