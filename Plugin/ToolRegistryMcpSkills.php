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
        private readonly ToolCatalog $catalog
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
        // Only a name shaped like an MCP skill may cost a look at the servers' tool lists.
        if ($result !== null || !str_starts_with($name, McpSkill::PREFIX)) {
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
                if (isset($result[$name]) || !$this->offered($subject, $skill, $adminUserId)) {
                    continue;
                }
                $added[$name] = $skill;
                $replaces = $this->catalog->serverConfig($skill->entry()->server)?->replacesSkill ?? '';
                if ($replaces !== '') {
                    $replaced[$replaces] = true;
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
     * Execution goes through getTool(), which never consults getEnabledTools(). Three things happen here:
     * an OAuth skill Mago could not find by name (getToolByName() carries no admin, so with nothing cached
     * the list was never fetched) is looked up for this admin, which is what makes a confirmed write run
     * in its own fresh request; an OAuth skill of a server this admin has not connected is blocked; and a
     * Mago skill a server replaces for this admin is blocked, not only hidden.
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
        try {
            if ($result === null) {
                if ($adminUserId === null || !str_starts_with($name, McpSkill::PREFIX)) {
                    return null;
                }
                $skill = $this->skills->byName($name, $adminUserId);

                return $skill !== null && $this->offered($subject, $skill, $adminUserId) ? $skill : null;
            }
            if ($result instanceof McpSkill) {
                $server = $this->catalog->serverConfig($result->entry()->server);

                return $server !== null && $this->catalog->connected($server, $adminUserId) ? $result : null;
            }
            if (!isset($this->catalog->replacedSkills()[$name])) {
                return $result;
            }
            foreach ($this->skills->all($adminUserId) as $skill) {
                if (($this->catalog->serverConfig($skill->entry()->server)?->replacesSkill ?? '') === $name
                    && $this->offered($subject, $skill, $adminUserId)
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
     * Whether this admin is offered this skill: its server is enabled and, for OAuth, connected by them;
     * Mago's own permission check allows it; and the role holds the skill's ACL resource.
     */
    private function offered(ToolRegistry $subject, McpSkill $skill, ?int $adminUserId): bool
    {
        $server = $this->catalog->serverConfig($skill->entry()->server);

        return $server !== null
            && $this->catalog->connected($server, $adminUserId)
            && $subject->isCallAllowed($skill, [], $adminUserId)
            && $this->authorization->isAllowed($skill->getMagentoAcl());
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
