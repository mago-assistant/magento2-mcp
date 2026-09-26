<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Tool\Mcp;

use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;

/**
 * Every tool of every enabled server as one Mago skill, built once per request.
 * Two tools whose names sanitise to the same skill name (for example "a.b" and "a:b") cannot both be
 * skills: the first one wins and the collision is logged, without inventing a suffix. A name AI providers
 * reject (anything but [A-Za-z0-9_-]) is left out and logged; nameFor() keeps every name within 64 characters.
 */
class SkillRegistry
{
    private const NAME_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** @var array<string,McpSkill[]> skills keyed by name, per admin id ("" for none), once per request */
    private array $skills = [];

    public function __construct(
        private readonly ToolCatalog $catalog,
        private readonly Executor $executor,
        private readonly ErrorLogger $errorLogger,
        private readonly ModeClassifier $classifier
    ) {
    }

    /**
     * @return McpSkill[]
     */
    public function all(?int $adminUserId = null): array
    {
        $key = (string)($adminUserId ?? '');
        if (!isset($this->skills[$key])) {
            $instructions = $this->catalog->serverInstructions($adminUserId);
            $gate = new InstructionGate();
            $skills = [];
            foreach ($this->catalog->entries($adminUserId) as $entry) {
                $skill = new McpSkill(
                    $entry,
                    $this->executor,
                    $instructions[$entry->server] ?? '',
                    $this->classifier->isExecutionSurface($entry->tool),
                    $gate
                );
                $name = $skill->getName();
                if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                    $this->errorLogger->addLog('MCP skill name unusable', [
                        'name' => $name,
                        'server' => $entry->server,
                        'tool' => $entry->tool,
                        'reason' => 'must match ^[A-Za-z0-9_-]{1,64}$',
                    ]);
                    continue;
                }
                if (isset($skills[$name])) {
                    $this->errorLogger->addLog(
                        'MCP skill name collision',
                        ['name' => $name, 'server' => $entry->server, 'tool' => $entry->tool]
                    );
                    continue;
                }
                $skills[$name] = $skill;
            }
            $this->skills[$key] = $skills;
        }

        return array_values($this->skills[$key]);
    }

    public function byName(string $name, ?int $adminUserId = null): ?McpSkill
    {
        $this->all($adminUserId);

        return $this->skills[(string)($adminUserId ?? '')][$name] ?? null;
    }

    public function isMcpSkill(string $name, ?int $adminUserId = null): bool
    {
        return $this->byName($name, $adminUserId) !== null;
    }
}
