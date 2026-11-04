<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Plugin;

use MagoAssistant\Mago\Service\Ai\ChatService;
use MagoAssistant\Mcp\Service\Chat\ToolEventRelabeler;

/**
 * Wraps the chat event emitter Mago hands to its streaming entry points, so an MCP skill's status
 * line reads in Mago's tone. Depends on Mago's event shapes (ChatService is not @api); unknown
 * shapes pass through untouched.
 */
class ChatServiceToolDisplay
{
    public function __construct(private readonly ToolEventRelabeler $relabeler)
    {
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array{0:array<int,array<string,mixed>>,1:callable,2:int|null,3:int|null}
     */
    public function beforeProcessMessageStreaming(
        ChatService $subject,
        array $messages,
        callable $onChunk,
        ?int $conversationId = null,
        ?int $adminUserId = null
    ): array {
        return [$messages, $this->relabeler->wrap($onChunk), $conversationId, $adminUserId];
    }

    /**
     * @param array<int,array<string,mixed>> $toolCalls
     * @param string[]|null $selectedIds
     * @return array{0:array<int,array<string,mixed>>,1:int|null,2:callable|null,3:string[]|null,4:int|null}
     */
    public function beforeExecuteConfirmedTools(
        ChatService $subject,
        array $toolCalls,
        ?int $adminUserId = null,
        ?callable $onChunk = null,
        ?array $selectedIds = null,
        ?int $conversationId = null
    ): array {
        return [
            $toolCalls,
            $adminUserId,
            $onChunk === null ? null : $this->relabeler->wrap($onChunk),
            $selectedIds,
            $conversationId,
        ];
    }
}
