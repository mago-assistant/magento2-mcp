<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Plugin;

use MagoAssistant\Mago\Service\Ai\ChatService;
use MagoAssistant\Mcp\Service\Chat\ToolEventRelabeler;

/**
 * Wraps the chat event emitter Mago hands to its streaming entry points, so the addon's own tool
 * events carry the server and tool instead of the bare skill name. Depends on Mago's event shapes
 * (ChatService is not @api); unknown shapes pass through untouched.
 */
class ChatServiceToolDisplay
{
    public function __construct(private readonly ToolEventRelabeler $relabeler)
    {
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array<string,mixed>
     */
    public function aroundProcessMessageStreaming(
        ChatService $subject,
        callable $proceed,
        array $messages,
        callable $onChunk,
        ?int $conversationId = null,
        ?int $adminUserId = null
    ): array {
        return $proceed($messages, $this->relabeler->wrap($onChunk), $conversationId, $adminUserId);
    }

    /**
     * @param array<int,array<string,mixed>> $toolCalls
     * @param string[]|null $selectedIds
     * @return array<string,mixed>
     */
    public function aroundExecuteConfirmedTools(
        ChatService $subject,
        callable $proceed,
        array $toolCalls,
        ?int $adminUserId = null,
        ?callable $onChunk = null,
        ?array $selectedIds = null,
        ?int $conversationId = null
    ): array {
        $wrapped = $onChunk === null ? null : $this->relabeler->wrap($onChunk, $toolCalls, $selectedIds);

        return $proceed($toolCalls, $adminUserId, $wrapped, $selectedIds, $conversationId);
    }
}
