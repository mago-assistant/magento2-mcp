<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;
use MagoAssistant\Mcp\Service\Discovery\NameMigration;

final class FakeServerRepository implements ServerRepositoryInterface
{
    /** @var array<string,array<string,mixed>> */
    public array $rows = [];

    /**
     * @param array<string,mixed> $extra columns to override, e.g. ['output_public' => true, 'label' => 'Demo']
     */
    public function add(string $name, bool $enabled, string $source = 'composer', array $extra = []): void
    {
        $name = DiscoveredServer::normaliseName($name);
        $this->rows[$name] = $extra + ['server_id' => count($this->rows) + 1, 'name' => $name, 'command' => ['php', $name],
            'env' => [], 'cwd' => null, 'label' => '', 'transport' => 'stdio', 'url' => '', 'auth_type' => 'none',
            'allowed_tools' => [], 'timeout' => null, 'output_public' => false, 'replaces_skill' => '', 'read_only' => false,
            'source' => $source, 'enabled' => $enabled, 'missing' => false, 'last_error' => null];
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
        return $this->rows[DiscoveredServer::normaliseName($name)] ?? null;
    }

    public function save(array $row): void
    {
        $name = DiscoveredServer::normaliseName((string)$row['name']);
        $this->rows[$name] = ['name' => $name] + $row + ['server_id' => count($this->rows) + 1];
    }

    public function setEnabled(string $name, bool $enabled): void
    {
        $this->rows[DiscoveredServer::normaliseName($name)]['enabled'] = $enabled;
    }

    public function setLastError(string $name, ?string $error): void
    {
        $this->rows[DiscoveredServer::normaliseName($name)]['last_error'] = $error;
    }

    public function merge(array $discovered): array
    {
        return ['inserted' => 0, 'updated' => 0, 'missing' => 0, 'names' => []];
    }

    public function migrateLegacyNames(): array
    {
        $plan = NameMigration::plan(array_values($this->rows));
        foreach ($plan['delete'] as $name) {
            unset($this->rows[$name]);
        }
        foreach ($plan['rename'] as $old => $new) {
            $row = $this->rows[$old];
            unset($this->rows[$old]);
            $row['name'] = $new;
            $this->rows[$new] = $row;
        }

        return ['renamed' => count($plan['rename']), 'deleted' => count($plan['delete'])];
    }
}
