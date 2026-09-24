<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Api;

use MagoAssistant\Mcp\Service\Mcp\McpException;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;

/**
 * One request to one MCP server. Implementations own the transport and the protocol handshake.
 *
 * @api
 */
interface McpClientInterface
{
    /**
     * Every tool the server exposes (all pages of tools/list merged) plus what initialize returned.
     *
     * @return array{tools: array<int,array<string,mixed>>, serverInfo: array<string,mixed>, instructions: string}
     * @throws McpException
     */
    public function listTools(ServerConfig $server, int $timeoutSeconds): array;

    /**
     * The raw tools/call result: content (array of blocks), isError, optional structuredContent.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws McpException
     */
    public function callTool(ServerConfig $server, string $tool, array $arguments, int $timeoutSeconds): array;
}
