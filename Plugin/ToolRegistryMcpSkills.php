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
use MagoAssistant\Mcp\Service\Auth\AuthenticatorResolver;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Tool\Mcp\McpSkill;
use MagoAssistant\Mcp\Service\Tool\Mcp\SkillRegistry;

/**
 * Adds one skill per MCP tool to Mago's registry through "after" plugins, so Skills & Permissions,
 * the chat and the confirmation flow see them like any other skill. Mago's getToolDefinitions() and
 * getTool() call getEnabledTools() and getToolByName() on $this, which the interceptor routes through
 * these plugins as well. A skill is offered in getEnabledTools() only when the subject's isCallAllowed()
 * allows it (Mago's per-user permission, else Mago's read/write ACL) and the admin's role holds the
 * skill's own ACL resource (MagoAssistant_Mcp::use for reads, ::use_write for writes), so a role is not
 * offered a skill Mago would refuse at execution. afterGetTool() blocks what afterGetEnabledTools() hides:
 * an OAuth server's skill for an admin without a connection, and a Mago skill a server replaces. Mago
 * checks the ACL resource again when the skill runs. A failure inside the addon leaves Mago's own result
 * untouched.
 */
class ToolRegistryMcpSkills
{
    public function __construct(
        private readonly SkillRegistry $skills,
        private readonly ErrorLogger $errorLogger,
        private readonly AuthorizationInterface $authorization,
        private readonly ToolCatalog $catalog,
        private readonly AuthenticatorResolver $authenticators
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
            $replaced = [];
            foreach ($this->skills->all($adminUserId) as $skill) {
                $name = $skill->getName();
                $server = $this->catalog->serverConfig($skill->entry()->server);
                if (!isset($result[$name])
                    && $server !== null
                    && $this->catalog->connected($server, $adminUserId)
                    && $subject->isCallAllowed($skill, [], $adminUserId)
                    && $this->authorization->isAllowed($skill->getMagentoAcl())
                ) {
                    $added[$name] = $skill;
                    if ($server->replacesSkill !== '') {
                        $replaced[$server->replacesSkill] = true;
                    }
                }
            }
            // The server is leading: the Mago skill it replaces is dropped for this admin only.
            foreach (array_keys($replaced) as $replacedName) {
                unset($result[$replacedName]);
            }

            return $result + $added;
        } catch (\Throwable $e) {
            $this->logUnavailable($e);

            return $result;
        }
    }

    /**
     * Execution goes through getTool(), which never consults getEnabledTools(): so a replaced skill, and an
     * OAuth server's skill for an admin without a connection, are blocked here too, not only hidden.
     *
     * @param ToolRegistry $subject
     * @param ToolInterface|null $result
     * @param string $name
     * @param int|null $adminUserId
     * @return ToolInterface|null
     */
    public function afterGetTool(
        ToolRegistry $subject,
        ?ToolInterface $result,
        string $name,
        ?int $adminUserId = null
    ): ?ToolInterface {
        if ($result === null) {
            return null;
        }
        try {
            if ($result instanceof McpSkill) {
                $server = $this->catalog->serverConfig($result->entry()->server);

                return $server !== null && $this->catalog->connected($server, $adminUserId) ? $result : null;
            }
            foreach ($this->skills->all($adminUserId) as $skill) {
                $server = $this->catalog->serverConfig($skill->entry()->server);
                if ($server !== null
                    && $server->replacesSkill === $name
                    && $this->catalog->connected($server, $adminUserId)
                    && $subject->isCallAllowed($skill, [], $adminUserId)
                    && $this->authorization->isAllowed($skill->getMagentoAcl())
                ) {
                    return null;
                }
            }

            return $result;
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
