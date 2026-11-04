<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use MagoAssistant\Mcp\Api\TransportInterface;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;

/**
 * A transport that fails with something other than an McpException, as a port that forgot to wrap a
 * client error or a malformed response would.
 */
final class ThrowingTransport implements TransportInterface
{
    public function listTools(ServerConfig $server, ?int $adminUserId = null): array
    {
        throw new \TypeError('malformed response');
    }

    public function callTool(ServerConfig $server, string $tool, array $arguments, ?int $adminUserId = null): array
    {
        throw new \TypeError('malformed response');
    }
}
