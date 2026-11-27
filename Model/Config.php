<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{
    public const XML_PATH_ENABLED = 'mago/mcp/enabled';
    public const XML_PATH_SCAN_MCP_JSON = 'mago/mcp/scan_mcp_json';
    public const XML_PATH_PROCESS_TIMEOUT = 'mago/mcp/process_timeout';
    public const XML_PATH_CACHE_LIFETIME = 'mago/mcp/cache_lifetime';
    public const XML_PATH_MAX_RESULT_CHARS = 'mago/mcp/max_result_chars';
    public const XML_PATH_WORKER_ADDRESS = 'mago/mcp/worker_address';
    public const XML_PATH_WORKER_LISTEN = 'mago/mcp/worker_listen';
    public const XML_PATH_WORKER_AUTOSTART = 'mago/mcp/worker_autostart';
    public const DEFAULT_WORKER_ADDRESS = '127.0.0.1:8765';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function isScanMcpJsonEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SCAN_MCP_JSON);
    }

    public function getProcessTimeout(): int
    {
        return $this->positiveInt(self::XML_PATH_PROCESS_TIMEOUT, 60);
    }

    public function getCacheLifetime(): int
    {
        return $this->positiveInt(self::XML_PATH_CACHE_LIFETIME, 3600);
    }

    public function getMaxResultChars(): int
    {
        return $this->positiveInt(self::XML_PATH_MAX_RESULT_CHARS, 16000);
    }

    /**
     * Where a web request reaches the MCP worker (host:port).
     */
    public function getWorkerAddress(): string
    {
        $value = trim((string)$this->scopeConfig->getValue(self::XML_PATH_WORKER_ADDRESS));

        return $value !== '' ? $value : self::DEFAULT_WORKER_ADDRESS;
    }

    /**
     * What the worker binds; the worker address unless the worker runs where it must bind another one,
     * such as a container's own interface.
     */
    public function getWorkerListen(): string
    {
        $value = trim((string)$this->scopeConfig->getValue(self::XML_PATH_WORKER_LISTEN));

        return $value !== '' ? $value : $this->getWorkerAddress();
    }

    public function isWorkerAutostart(): bool
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_WORKER_AUTOSTART);

        return $value === null || (bool)$value;
    }

    private function positiveInt(string $path, int $default): int
    {
        $value = (int)($this->scopeConfig->getValue($path) ?? 0);

        return $value > 0 ? $value : $default;
    }
}
