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
            if (!is_array($entry) || !is_string($entry['command'] ?? null) || isset($entry['url'])
                || (isset($entry['type']) && $entry['type'] !== 'stdio')) {
                continue; // http/sse entries cannot be spawned; nothing to log, they are simply not ours
            }
            $args = array_values(array_map('strval', is_array($entry['args'] ?? null) ? $entry['args'] : []));
            $env = array_map('strval', is_array($entry['env'] ?? null) ? $entry['env'] : []);
            $unwrapped = $this->unwrapper->unwrap($entry['command'], $args, $env);
            $name = DiscoveredServer::normaliseName((string)$rawName);
            if ($name === '') {
                continue;
            }
            $servers[] = $unwrapped === null
                ? new DiscoveredServer($name, [$entry['command'], ...$args], $env, null, DiscoveredServer::SOURCE_MCP_JSON)
                : new DiscoveredServer($name, $unwrapped['command'], $unwrapped['env'], $unwrapped['cwd'], DiscoveredServer::SOURCE_MCP_JSON);
        }

        return $servers;
    }
}
