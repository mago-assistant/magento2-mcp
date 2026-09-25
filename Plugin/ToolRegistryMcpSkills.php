<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Plugin;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mcp\Service\Tool\Mcp\SkillRegistry;

/**
 * Adds one skill per MCP tool to Mago's registry through "after" plugins, so Skills & Permissions,
 * the chat and the confirmation flow see them like any other skill. Mago's getToolDefinitions() and
 * getTool() call getEnabledTools() and getToolByName() on $this, which the interceptor routes through
 * these plugins as well. A skill is offered in getEnabledTools() only when the subject's isCallAllowed()
 * allows it (Mago's per-user permission, else Mago's read/write ACL) and the admin's role holds the
 * skill's own ACL resource (MagoAssistant_Mcp::use for reads, ::use_write for writes), so a role is not
 * offered a skill Mago would refuse at execution. getTool() still goes through Mago's own logic, and Mago
 * checks the ACL resource again when the skill runs. A failure inside the addon leaves Mago's own result
 * untouched.
 */
class ToolRegistryMcpSkills
{
    public function __construct(
        private readonly SkillRegistry $skills,
        private readonly ErrorLogger $errorLogger,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    /**
     * @param ToolRegistry $subject
     * @param ToolInterface[] $result
     * @return ToolInterface[]
     */
    public function afterGetAllTools(ToolRegistry $subject, array $result): array
    {
        try {
            return array_merge($result, $this->skills->all());
        } catch (\Throwable $e) {
            $this->logUnavailable($e);

            return $result;
        }
    }

    /**
     * @param ToolRegistry $subject
     * @param ToolInterface|null $result
     * @param string $name
     * @return ToolInterface|null
     */
    public function afterGetToolByName(ToolRegistry $subject, ?ToolInterface $result, string $name): ?ToolInterface
    {
        if ($result !== null) {
            return $result;
        }
        try {
            return $this->skills->byName($name);
        } catch (\Throwable $e) {
            $this->logUnavailable($e);

            return null;
        }
    }

    /**
     * @param ToolRegistry $subject
     * @param ToolInterface[] $result keyed by name
     * @param int|null $adminUserId
     * @return ToolInterface[]
     */
    public function afterGetEnabledTools(ToolRegistry $subject, array $result, ?int $adminUserId = null): array
    {
        try {
            $added = [];
            foreach ($this->skills->all() as $skill) {
                $name = $skill->getName();
                if (!isset($result[$name])
                    && $subject->isCallAllowed($skill, [], $adminUserId)
                    && $this->authorization->isAllowed($skill->getMagentoAcl())
                ) {
                    $added[$name] = $skill;
                }
            }

            return $result + $added;
        } catch (\Throwable $e) {
            $this->logUnavailable($e);

            return $result;
        }
    }

    /**
     * @param \Throwable $e
     * @return void
     */
    private function logUnavailable(\Throwable $e): void
    {
        $this->errorLogger->addLog(
            'MCP skills unavailable',
            ['error' => $e->getMessage(), 'exception' => get_class($e)]
        );
    }
}
