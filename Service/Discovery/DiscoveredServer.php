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

    private const NAME_PREFIXES = ['magento2-', 'magento-', 'module-'];
    private const NAME_SUFFIXES = ['-mcp-server', '-mcp'];

    /**
     * Lower-case, [a-z0-9_-] only, with the vendor-ish prefixes and "-mcp" suffixes stripped, so a
     * package named inchoo/magento-bricklayer and a .mcp.json entry named "Magento Bricklayer" both
     * become "bricklayer": one row, and the shipped defaults keyed on that name apply to either.
     */
    public static function normaliseName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = trim(preg_replace('/[^a-z0-9_-]+/', '-', $name) ?? '', '-');
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

        return trim($name, '-');
    }
}
