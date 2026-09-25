<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Mcp;

use MagoAssistant\Mcp\Service\Discovery\ServerDefinition;

final class ServerConfig
{
    public const TRANSPORT_STDIO = 'stdio';
    public const TRANSPORT_HTTP = 'http';
    public const AUTH_NONE = 'none';
    public const AUTH_BEARER = 'bearer';
    public const AUTH_OAUTH = 'oauth';

    /**
     * @param string[] $command Executable and arguments, run without a shell (stdio); [] for http
     * @param array<string,string> $env Extra environment variables (stdio)
     * @param string|null $cwd Working directory, relative to the Magento root when not absolute (stdio)
     * @param string $label Shown to admins and in tool descriptions; '' falls back to the name
     * @param string $transport TRANSPORT_STDIO or TRANSPORT_HTTP; the resolver rejects anything else
     * @param string $url MCP endpoint (http)
     * @param string $authType AUTH_NONE, AUTH_BEARER or AUTH_OAUTH (http)
     * @param string $bearerToken Decrypted token (http, AUTH_BEARER)
     * @param string[] $allowedTools Tool names the assistant may use; [] allows every tool listed
     * @param int $timeout Seconds per call, both transports
     * @param bool $outputPublic Whether a result may cross to the AI provider as declared-public data
     * @param string $replacesSkill Mago skill hidden for admins offered this server, or ''
     * @param array<string,array<string,array{0:string,1?:string}>> $fieldClassificationOverrides per tool
     * @param array<string,string> $errorHints error fragment => hint appended to that error
     */
    public function __construct(
        public readonly string $name,
        public readonly array $command,
        public readonly array $env = [],
        public readonly ?string $cwd = null,
        public readonly string $label = '',
        public readonly string $transport = self::TRANSPORT_STDIO,
        public readonly string $url = '',
        public readonly string $authType = self::AUTH_NONE,
        public readonly string $bearerToken = '',
        public readonly array $allowedTools = [],
        public readonly int $timeout = 60,
        public readonly bool $outputPublic = false,
        public readonly string $replacesSkill = '',
        public readonly array $fieldClassificationOverrides = [],
        public readonly array $errorHints = []
    ) {
    }

    public function label(): string
    {
        return $this->label !== '' ? $this->label : $this->name;
    }

    /**
     * @param array<string,mixed> $row A decoded mago_mcp_server row
     * @param int $defaultTimeout Used when the row's timeout column is null
     * @param ServerDefinition|null $definition The module definition for a "module" row: its per-tool field
     *        classification and error hints are never stored, so they come from here
     */
    public static function fromRow(array $row, int $defaultTimeout, ?ServerDefinition $definition = null): self
    {
        $env = [];
        foreach (is_array($row['env'] ?? null) ? $row['env'] : [] as $key => $value) {
            if (is_scalar($value)) {
                $env[(string)$key] = (string)$value;
            }
        }
        $timeout = $row['timeout'] ?? null;

        return new self(
            (string)$row['name'],
            self::stringList($row['command'] ?? null),
            $env,
            isset($row['cwd']) && $row['cwd'] !== '' ? (string)$row['cwd'] : null,
            (string)($row['label'] ?? ''),
            (string)($row['transport'] ?? self::TRANSPORT_STDIO),
            (string)($row['url'] ?? ''),
            (string)($row['auth_type'] ?? self::AUTH_NONE),
            (string)($row['bearer_token'] ?? ''),
            self::stringList($row['allowed_tools'] ?? null),
            is_numeric($timeout) && (int)$timeout > 0 ? (int)$timeout : $defaultTimeout,
            (bool)($row['output_public'] ?? false),
            (string)($row['replaces_skill'] ?? ''),
            $definition?->fieldClassificationOverrides ?? [],
            $definition?->errorHints ?? []
        );
    }

    /**
     * @return string[]
     */
    private static function stringList(mixed $value): array
    {
        $list = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_scalar($item)) {
                $list[] = (string)$item;
            }
        }

        return $list;
    }
}
