<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Chat;

use MagoAssistant\Mcp\Service\Tool\Mcp\McpSkill;
use MagoAssistant\Mcp\Service\Tool\Mcp\SkillRegistry;

/**
 * Gives an MCP skill's status line Mago's tone. Mago tags the panel with the skill name, which for
 * this addon is already "<server>__<tool>", so only the fallback message "Running <name>..." is
 * replaced, by a phrase built from the tool part: "order-get" reads as "Getting order...".
 */
class ToolEventRelabeler
{
    private const GERUNDS = [
        'get' => 'Getting', 'list' => 'Listing', 'search' => 'Searching', 'find' => 'Finding', 'show' => 'Showing',
        'read' => 'Reading', 'view' => 'Viewing', 'inspect' => 'Inspecting', 'check' => 'Checking',
        'validate' => 'Validating', 'diagnose' => 'Diagnosing', 'analyze' => 'Analyzing', 'count' => 'Counting',
        'create' => 'Creating', 'update' => 'Updating', 'delete' => 'Deleting', 'remove' => 'Removing',
        'add' => 'Adding', 'set' => 'Setting', 'assign' => 'Assigning', 'cancel' => 'Cancelling',
        'hold' => 'Holding', 'unhold' => 'Releasing', 'generate' => 'Generating', 'run' => 'Running',
        'execute' => 'Executing', 'kill' => 'Stopping', 'schedule' => 'Scheduling', 'reindex' => 'Reindexing',
        'flush' => 'Flushing', 'clean' => 'Cleaning', 'clear' => 'Clearing', 'refresh' => 'Refreshing',
        'import' => 'Importing', 'export' => 'Exporting', 'sync' => 'Syncing', 'save' => 'Saving',
        'send' => 'Sending', 'apply' => 'Applying', 'reinitialize' => 'Reinitializing',
    ];

    public function __construct(private readonly SkillRegistry $skills)
    {
    }

    /**
     * Only a name containing the separator reaches the skill lookup. The relabel decision runs inside a
     * try: if the lookup fails (a missing table, a broken server), the message stays as Mago sent it.
     * The chunk is always passed on, outside the try.
     */
    public function wrap(callable $onChunk): callable
    {
        return function (string $event, array $data) use ($onChunk): void {
            $name = (string)($data['name'] ?? '');
            if ($event === 'tool_status'
                && str_contains($name, McpSkill::SEPARATOR)
                && ($data['status'] ?? '') === 'running'
                && is_string($data['message'] ?? null)
                && str_starts_with($data['message'], 'Running ' . $name)
            ) {
                try {
                    // The skill's own tool name: the part after "__" is cut and hashed for long names.
                    $tool = $this->skills->byName($name)?->entry()->tool;
                    $phrase = $tool !== null ? $this->phraseFor($tool) : null;
                } catch (\Throwable) {
                    $phrase = null;
                }
                if ($phrase !== null) {
                    $data['message'] = $phrase;
                }
            }
            $onChunk($event, $data);
        };
    }

    /**
     * "order-get" reads as "Getting order...", in the style of Mago's own status lines.
     */
    private function phraseFor(string $tool): string
    {
        $words = array_values(array_filter(preg_split('/[-_]/', strtolower($tool)) ?: []));
        if ($words === []) {
            return 'Running ' . $tool . '...';
        }
        $verb = 'Running';
        $rest = $words;
        foreach ($words as $index => $word) {
            if (isset(self::GERUNDS[$word])) {
                $verb = self::GERUNDS[$word];
                $rest = array_merge(array_slice($words, 0, $index), array_slice($words, $index + 1));
                break;
            }
        }

        return trim($verb . ' ' . implode(' ', $rest)) . '...';
    }
}
