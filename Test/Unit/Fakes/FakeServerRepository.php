<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use MagoAssistant\Mcp\Api\ServerRepositoryInterface;

final class FakeServerRepository implements ServerRepositoryInterface
{
    /** @var array<string,array<string,mixed>> */
    public array $rows = [];

    public function add(string $name, bool $enabled, array $overrides = [], string $source = 'composer'): void
    {
        $this->rows[$name] = ['server_id' => count($this->rows) + 1, 'name' => $name, 'command' => ['php', $name],
            'env' => [], 'cwd' => null, 'source' => $source, 'enabled' => $enabled, 'missing' => false,
            'tool_overrides' => $overrides, 'last_error' => null];
    }

    public function getAll(): array
    {
        return array_values($this->rows);
    }

    public function getEnabled(): array
    {
        return array_values(array_filter($this->rows, static fn (array $r): bool => $r['enabled']));
    }

    public function getByName(string $name): ?array
    {
        return $this->rows[$name] ?? null;
    }

    public function save(array $row): void
    {
        $this->rows[$row['name']] = $row + ['server_id' => count($this->rows) + 1];
    }

    public function setEnabled(string $name, bool $enabled): void
    {
        $this->rows[$name]['enabled'] = $enabled;
    }

    public function setToolOverrides(string $name, array $overrides): void
    {
        $this->rows[$name]['tool_overrides'] = $overrides;
    }

    public function setLastError(string $name, ?string $error): void
    {
        $this->rows[$name]['last_error'] = $error;
    }

    public function merge(array $discovered): array
    {
        return ['inserted' => 0, 'updated' => 0, 'missing' => 0];
    }
}
