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
 * reject (anything but 1 to 64 of [A-Za-z0-9_-]) is left out and logged as well.
 */
class SkillRegistry
{
    private const NAME_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** @var McpSkill[]|null keyed by skill name, once per request */
    private ?array $skills = null;

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
    public function all(): array
    {
        if ($this->skills === null) {
            $instructions = $this->catalog->serverInstructions();
            $skills = [];
            foreach ($this->catalog->entries() as $entry) {
                $skill = new McpSkill(
                    $entry,
                    $this->executor,
                    $instructions[$entry->server] ?? '',
                    $this->classifier->isExecutionSurface($entry->tool)
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
            $this->skills = $skills;
        }

        return array_values($this->skills);
    }

    public function byName(string $name): ?McpSkill
    {
        $this->all();

        return $this->skills[$name] ?? null;
    }

    public function isMcpSkill(string $name): bool
    {
        return $this->byName($name) !== null;
    }
}
