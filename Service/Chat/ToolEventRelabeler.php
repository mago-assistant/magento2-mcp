<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Chat;

use MagoAssistant\Mcp\Service\Tool\McpTool;

/**
 * Relabels the addon's own chat events so the panel reads like Mago's own skills: a short tag for
 * the server and a plain-language status line for the tool, instead of the bare skill name "mcp".
 *
 * Mago names the tool tag and the status line after the tool, which for this addon is always "mcp".
 * The action ("server:tool") travels in the call's input, so the wrapper reads it from each tool_call
 * event, queues the server tag and a plain-English phrase for the tool, and applies that pair to the
 * following tool_status events for the same tool in order. Everything that is not an "mcp" event
 * passes through untouched.
 */
class ToolEventRelabeler
{
    private const RUNNING_PREFIX = 'Running ' . McpTool::NAME;

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

    /**
     * @param callable $onChunk Mago's (string $event, array $data) emitter
     * @param array<int,array<string,mixed>> $confirmedCalls Tool calls about to run without tool_call events (confirm round-trip)
     * @param string[]|null $selectedIds Ids the admin ticked on the confirmation card; null means all
     */
    public function wrap(callable $onChunk, array $confirmedCalls = [], ?array $selectedIds = null): callable
    {
        $queue = [];
        foreach ($confirmedCalls as $call) {
            $selected = $selectedIds === null || in_array((string)($call['id'] ?? ''), $selectedIds, true);
            if ($selected && ($call['name'] ?? null) === McpTool::NAME) {
                $input = $call['input'] ?? null;
                $queue[] = ['tag' => $this->tag($input), 'message' => $this->phrase($input)];
            }
        }
        $current = McpTool::NAME;

        return function (string $event, array $data) use ($onChunk, &$queue, &$current): void {
            if (($data['name'] ?? null) !== McpTool::NAME) {
                $onChunk($event, $data);

                return;
            }
            if ($event === 'tool_call') {
                // The call carries its own input, so the tag is always exact.
                $input = $data['input'] ?? null;
                $queue[] = ['tag' => $this->tag($input), 'message' => $this->phrase($input)];
                $data['name'] = $this->tag($input);
            } elseif ($event === 'tool_status') {
                if (($data['status'] ?? '') === 'running') {
                    // Mago emits no status for a denied or unticked call, so with several calls queued
                    // there is no way to know which one this is: keep the generic name rather than guess.
                    $pair = count($queue) === 1 ? array_shift($queue) : null;
                    $current = $pair !== null ? $pair['tag'] : McpTool::NAME;
                    $queue = [];
                    if ($pair !== null && is_string($data['message'] ?? null)
                        && str_starts_with($data['message'], self::RUNNING_PREFIX)) {
                        $data['message'] = $pair['message'];
                    }
                }
                $data['name'] = $current;
            }
            $onChunk($event, $data);
        };
    }

    /**
     * "server:tool" becomes "server"; anything else keeps the tool name.
     */
    private function tag(mixed $input): string
    {
        $action = is_array($input) ? (string)($input['action'] ?? '') : '';
        $parts = explode(':', $action, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return McpTool::NAME;
        }

        // The full path, "mcp:server:tool", so the tag alone says which skill, server and tool ran.
        return McpTool::NAME . ':' . $parts[0] . ':' . $parts[1];
    }

    /**
     * "order-get" reads as "Getting order...", in the style of Mago's own status lines.
     */
    private function phrase(mixed $input): string
    {
        $action = is_array($input) ? (string)($input['action'] ?? '') : '';
        $parts = explode(':', $action, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return 'Running ' . McpTool::NAME . '...';
        }

        return $this->phraseFor($parts[1]);
    }

    /**
     * "order-get" reads as "Getting order...", in the style of Mago's own status lines.
     */
    private function phraseFor(string $tool): string
    {
        $words = array_values(array_filter(preg_split('/[-_]/', strtolower($tool)) ?: []));
        if ($words === []) {
            return 'Running ' . McpTool::NAME . '...';
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
