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
    public const SOURCE_MODULE = 'module';
    public const SOURCE_MANUAL = 'manual';

    /**
     * @param string[] $command stdio argv; [] for http
     * @param array<string,string> $env
     * @param string[] $allowedTools
     */
    public function __construct(
        public readonly string $name,
        public readonly array $command,
        public readonly array $env,
        public readonly ?string $cwd,
        public readonly string $source,
        public readonly string $label = '',
        public readonly string $transport = 'stdio',
        public readonly string $url = '',
        public readonly string $authType = 'none',
        public readonly string $bearerToken = '',
        public readonly array $allowedTools = [],
        public readonly ?int $timeout = null,
        public readonly bool $outputPublic = false,
        public readonly string $replacesSkill = '',
        public readonly bool $readOnly = false
    ) {
    }

    /**
     * The storable columns, in the decoded shape ServerRepositoryInterface::save() accepts. The bearer
     * token is present only when the source supplied one, so a rescan never wipes a token stored on the
     * row by other means.
     *
     * @return array<string,mixed>
     */
    public function toRow(): array
    {
        $row = [
            'name' => $this->name,
            'command' => $this->command,
            'env' => $this->env,
            'cwd' => $this->cwd,
            'source' => $this->source,
            'label' => $this->label,
            'transport' => $this->transport,
            'url' => $this->url,
            'auth_type' => $this->authType,
            'allowed_tools' => $this->allowedTools,
            'timeout' => $this->timeout,
            'output_public' => $this->outputPublic,
            'replaces_skill' => $this->replacesSkill,
            'read_only' => $this->readOnly,
        ];
        if ($this->bearerToken !== '') {
            $row['bearer_token'] = $this->bearerToken;
        }

        return $row;
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
