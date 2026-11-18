<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Transport;

use MagoAssistant\Mcp\Api\TransportInterface;
use MagoAssistant\Mcp\Service\Mcp\McpException;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;

/**
 * The transport for a server's transport value, from the di.xml map: adding a transport is one
 * di.xml item, and a row whose value has no implementation fails as that server's own error.
 */
final class TransportResolver
{
    /**
     * @param array<string,mixed> $transports keyed by ServerConfig::transport value
     */
    public function __construct(private readonly array $transports = [])
    {
    }

    /**
     * @throws McpException
     */
    public function for(ServerConfig $server): TransportInterface
    {
        $transport = $this->transports[$server->transport] ?? null;
        if (!$transport instanceof TransportInterface) {
            throw new McpException(sprintf(
                'MCP server "%s" uses transport "%s", which is not available.',
                $server->name,
                $server->transport
            ));
        }

        return $transport;
    }
}
