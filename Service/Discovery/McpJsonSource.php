<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Discovery;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;
use MagoAssistant\Mago\Logger\ErrorLogger;

/**
 * Servers configured for Claude Code or Cursor in the Magento root's .mcp.json.
 */
class McpJsonSource implements SourceInterface
{
    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly File $file,
        private readonly DockerExecUnwrapper $unwrapper,
        private readonly ErrorLogger $errorLogger
    ) {
    }

    public function discover(): array
    {
        $path = rtrim($this->directoryList->getRoot(), '/') . '/.mcp.json';
        try {
            if (!$this->file->isReadable($path)) {
                return [];
            }
            $decoded = json_decode($this->file->fileGetContents($path), true);
        } catch (FileSystemException $e) {
            $this->errorLogger->addLog('MCP discovery', ['file' => '.mcp.json', 'error' => $e->getMessage()]);

            return [];
        }
        if (!is_array($decoded) || !is_array($decoded['mcpServers'] ?? null)) {
            $this->errorLogger->addLog('MCP discovery', ['file' => '.mcp.json', 'error' => 'not valid JSON with an mcpServers object; ignored']);

            return [];
        }
        $servers = [];
        foreach ($decoded['mcpServers'] as $rawName => $entry) {
            $name = DiscoveredServer::normaliseName((string)$rawName);
            if (!is_array($entry) || $name === '') {
                continue;
            }
            if (isset($entry['url'])) {
                $http = $this->http($name, $entry);
                if ($http !== null) {
                    $servers[] = $http;
                }
                continue;
            }
            if (!is_string($entry['command'] ?? null) || (isset($entry['type']) && $entry['type'] !== 'stdio')) {
                continue;
            }
            $args = array_values(array_map('strval', is_array($entry['args'] ?? null) ? $entry['args'] : []));
            $env = array_map('strval', is_array($entry['env'] ?? null) ? $entry['env'] : []);
            $unwrapped = $this->unwrapper->unwrap($entry['command'], $args, $env);
            $servers[] = $unwrapped === null
                ? new DiscoveredServer($name, [$entry['command'], ...$args], $env, null, DiscoveredServer::SOURCE_MCP_JSON)
                : new DiscoveredServer($name, $unwrapped['command'], $unwrapped['env'], $unwrapped['cwd'], DiscoveredServer::SOURCE_MCP_JSON);
        }

        return $servers;
    }

    /**
     * An entry with a url: type absent, "http" or "streamable-http" is a Streamable HTTP server; "sse" is
     * the older two-endpoint transport this module does not speak. An "Authorization: Bearer" header
     * becomes the row's bearer token; any other header is ignored and named in the log, never its value.
     *
     * @param array<string,mixed> $entry
     */
    private function http(string $name, array $entry): ?DiscoveredServer
    {
        $type = (string)($entry['type'] ?? 'http');
        if (!in_array($type, ['http', 'streamable-http'], true)) {
            $this->errorLogger->addLog(
                'MCP discovery',
                ['file' => '.mcp.json', 'server' => $name, 'skipped' => 'type ' . $type . ' is not Streamable HTTP']
            );

            return null;
        }
        $token = '';
        foreach (is_array($entry['headers'] ?? null) ? $entry['headers'] : [] as $header => $value) {
            if (strtolower((string)$header) === 'authorization'
                && is_string($value)
                && preg_match('/^Bearer\s+(\S+)$/i', $value, $m)
            ) {
                $token = $m[1];
                continue;
            }
            $this->errorLogger->addLog(
                'MCP discovery',
                ['file' => '.mcp.json', 'server' => $name, 'ignored_header' => (string)$header]
            );
        }

        return new DiscoveredServer(
            $name,
            [],
            [],
            null,
            DiscoveredServer::SOURCE_MCP_JSON,
            transport: 'http',
            url: (string)$entry['url'],
            authType: $token !== '' ? 'bearer' : 'none',
            bearerToken: $token
        );
    }
}
