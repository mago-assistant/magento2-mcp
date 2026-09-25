<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Api;

/**
 * A remote MCP server (Streamable HTTP transport) whose tools the assistant may call.
 *
 * @api
 */
interface ServerInterface
{
    /**
     * Unique code, used in the tool name (mcp_<code>) and in cache keys
     */
    public function getCode(): string;

    public function getLabel(): string;

    public function isEnabled(): bool;

    public function getUrl(): string;

    public function getAuthenticator(): AuthenticatorInterface;

    /**
     * Seconds to wait for a response before giving up
     */
    public function getTimeout(): int;

    /**
     * Remote tool names the assistant may use; an empty list allows every tool the server lists
     *
     * @return string[]
     */
    public function getAllowedTools(): array;

    /**
     * Privacy classification of a remote tool's output (see docs/privacy-mode). Undeclared fields
     * are stripped, so return [] unless the output is known to be safe to send to the AI provider.
     *
     * @param string $toolName
     * @return array<string, array{0: string, 1?: string}>
     */
    public function getFieldClassification(string $toolName): array;

    /**
     * Extra guidance appended to an error a remote tool returned (e.g. "list the domains first"), or ''
     */
    public function getErrorHint(string $toolName, string $error): string;
}
