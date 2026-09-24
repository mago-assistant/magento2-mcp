<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Mcp;

final class ServerConfig
{
    /**
     * @param string[] $command Executable and arguments, run without a shell
     * @param array<string,string> $env Extra environment variables
     * @param string|null $cwd Working directory, relative to the Magento root when not absolute
     */
    public function __construct(
        public readonly string $name,
        public readonly array $command,
        public readonly array $env = [],
        public readonly ?string $cwd = null
    ) {
    }

    /**
     * @param array<string,mixed> $row A decoded mago_mcp_server row
     */
    public static function fromRow(array $row): self
    {
        $env = [];
        foreach (is_array($row['env'] ?? null) ? $row['env'] : [] as $key => $value) {
            if (is_scalar($value)) {
                $env[(string)$key] = (string)$value;
            }
        }
        $command = [];
        foreach (is_array($row['command'] ?? null) ? $row['command'] : [] as $part) {
            if (is_scalar($part)) {
                $command[] = (string)$part;
            }
        }

        return new self(
            (string)$row['name'],
            $command,
            $env,
            isset($row['cwd']) && $row['cwd'] !== '' ? (string)$row['cwd'] : null
        );
    }
}
