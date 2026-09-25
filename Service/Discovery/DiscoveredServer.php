<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Discovery;

final class DiscoveredServer
{
    public const SOURCE_COMPOSER = 'composer';
    public const SOURCE_MCP_JSON = 'mcp_json';
    public const SOURCE_MANUAL = 'manual';

    /**
     * @param string[] $command
     * @param array<string,string> $env
     */
    public function __construct(
        public readonly string $name,
        public readonly array $command,
        public readonly array $env,
        public readonly ?string $cwd,
        public readonly string $source
    ) {
    }

    private const NAME_PREFIXES = ['magento2_', 'magento_', 'module_'];
    private const NAME_SUFFIXES = ['_mcp_server', '_mcp'];

    /**
     * Lower-case, [a-z0-9_] only, with the vendor-ish prefixes and "mcp" suffixes stripped, so a
     * package named acme/magento-widget and a .mcp.json entry named "Magento Widget" both become
     * "widget": one row, and the administrator's settings for that name apply to either. Underscore
     * only, so the name is already a valid segment of a skill name (mcp_<server>__<tool>) and
     * "a-b" and "a_b" cannot become the same skill.
     */
    public static function normaliseName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = trim(preg_replace('/[^a-z0-9_]+/', '_', $name) ?? '', '_');
        foreach (self::NAME_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)) {
                $name = substr($name, strlen($prefix));
            }
        }
        foreach (self::NAME_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix) && strlen($name) > strlen($suffix)) {
                $name = substr($name, 0, -strlen($suffix));
            }
        }

        return trim($name, '_');
    }
}
