<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Transport;

use MagoAssistant\Mcp\Api\TransportInterface;
use MagoAssistant\Mcp\Service\Mcp\McpException;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use MagoAssistant\Mcp\Service\Mcp\StdioSession;
use MagoAssistant\Mcp\Service\Mcp\StdioSessionFactory;

/**
 * Spawns the server for every request: initialize, initialized, the request, close.
 *
 * A server that boots the application on start costs a second or two per call; the catalog caches tools/list so
 * only real tool calls pay it. Persistent processes are out of scope for version 1. A stdio process has no
 * per-user identity, so $adminUserId is ignored.
 */
class StdioTransport implements TransportInterface
{
    public const PROTOCOL_VERSION = '2025-06-18';

    /** Versions that share this framing and tools API */
    private const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(private readonly StdioSessionFactory $sessionFactory)
    {
    }

    public function listTools(ServerConfig $server, ?int $adminUserId = null): array
    {
        [$session, $initialized] = $this->open($server);
        try {
            $tools = [];
            $cursor = null;
            do {
                $result = $session->request('tools/list', $cursor === null ? new \stdClass() : ['cursor' => $cursor]);
                foreach ($result['tools'] ?? [] as $tool) {
                    if (is_array($tool) && isset($tool['name'])) {
                        $tools[] = $tool;
                    }
                }
                $cursor = $result['nextCursor'] ?? null;
            } while (is_string($cursor) && $cursor !== '');

            return [
                'tools' => $tools,
                'serverInfo' => is_array($initialized['serverInfo'] ?? null) ? $initialized['serverInfo'] : [],
                'instructions' => is_string($initialized['instructions'] ?? null) ? $initialized['instructions'] : '',
            ];
        } finally {
            $session->close();
        }
    }

    public function callTool(ServerConfig $server, string $tool, array $arguments, ?int $adminUserId = null): array
    {
        [$session] = $this->open($server);
        try {
            return $session->request('tools/call', [
                'name' => $tool,
                'arguments' => $arguments === [] ? new \stdClass() : $arguments,
            ]);
        } finally {
            $session->close();
        }
    }

    /**
     * @return array{0: StdioSession, 1: array<string,mixed>} the open session and the initialize result
     */
    private function open(ServerConfig $server): array
    {
        $session = $this->sessionFactory->create($server, $server->timeout);
        $session->start();
        try {
            $initialized = $session->request('initialize', [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'mago-mcp', 'version' => '1.0.0'],
            ]);
            $version = (string)($initialized['protocolVersion'] ?? '');
            if (!in_array($version, self::SUPPORTED_VERSIONS, true)) {
                throw new McpException(sprintf(
                    'MCP server "%s" speaks protocol version "%s", which this client does not support.',
                    $server->name,
                    $version
                ));
            }
            $session->notify('notifications/initialized');
        } catch (\Throwable $e) {
            $session->close();
            throw $e;
        }

        return [$session, $initialized];
    }
}
