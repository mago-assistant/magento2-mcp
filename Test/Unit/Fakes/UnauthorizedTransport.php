<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use MagoAssistant\Mcp\Api\TransportInterface;
use MagoAssistant\Mcp\Service\Mcp\McpAuthenticationException;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;

/**
 * A transport whose server rejects the credentials every time.
 */
final class UnauthorizedTransport implements TransportInterface
{
    public int $calls = 0;

    public function listTools(ServerConfig $server, ?int $adminUserId = null): array
    {
        $this->calls++;
        throw new McpAuthenticationException(sprintf('MCP server "%s" rejected the credentials (401).', $server->name));
    }

    public function callTool(ServerConfig $server, string $tool, array $arguments, ?int $adminUserId = null): array
    {
        $this->calls++;
        throw new McpAuthenticationException(sprintf('MCP server "%s" rejected the credentials (401).', $server->name));
    }
}
