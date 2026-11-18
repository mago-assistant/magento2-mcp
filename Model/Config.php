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

    private function positiveInt(string $path, int $default): int
    {
        $value = (int)($this->scopeConfig->getValue($path) ?? 0);

        return $value > 0 ? $value : $default;
    }
}
