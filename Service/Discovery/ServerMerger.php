<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Discovery;

/**
 * Pure merge rules between stored rows and a scan, so they can be tested without a database.
 */
class ServerMerger
{
    /**
     * The row a rescan writes for a known server: the source owns the connection details, the administrator
     * owns enabled, read_only and output_public (the source's value counts only when the row is inserted),
     * and the row keeps its last error.
     *
     * @param array<string,mixed> $existing
     * @return array<string,mixed>
     */
    /**
     * Whether a rescan moved an OAuth server to another URL: its tokens were issued for the old one.
     *
     * @param array<string,mixed> $existing
     */
    public static function credentialsStale(DiscoveredServer $server, array $existing): bool
    {
        return ($existing['auth_type'] ?? '') === 'oauth' && (string)($existing['url'] ?? '') !== $server->url;
    }

    /**
     * @param array<string,mixed> $existing
     * @return array<string,mixed>
     */
    public static function updatedRow(DiscoveredServer $server, array $existing): array
    {
        return [
            'enabled' => (bool)($existing['enabled'] ?? false),
            'read_only' => (bool)($existing['read_only'] ?? false),
            'output_public' => (bool)($existing['output_public'] ?? false),
            'missing' => false,
            'last_error' => $existing['last_error'] ?? null,
        ] + $server->toRow();
    }

    /**
     * @param array<string,array<string,mixed>> $existingByName
     * @param DiscoveredServer[] $discovered
     * @return array{insert: DiscoveredServer[], update: DiscoveredServer[], missing: string[], skipped: string[]}
     *         skipped: discovered names that met an administrator's manual row and were left alone
     */
    public function plan(array $existingByName, array $discovered): array
    {
        $insert = [];
        $update = [];
        $skipped = [];
        $seen = [];
        foreach ($discovered as $server) {
            $seen[$server->name] = true;
            $row = $existingByName[$server->name] ?? null;
            if ($row === null) {
                $insert[] = $server;
                continue;
            }
            if (($row['source'] ?? '') === DiscoveredServer::SOURCE_MANUAL) {
                $skipped[] = $server->name;
                continue;
            }
            $update[] = $server;
        }
        $missing = [];
        foreach ($existingByName as $name => $row) {
            if (($row['source'] ?? '') !== DiscoveredServer::SOURCE_MANUAL && !isset($seen[$name])) {
                $missing[] = (string)$name;
            }
        }

        return ['insert' => $insert, 'update' => $update, 'missing' => $missing, 'skipped' => $skipped];
    }
}
