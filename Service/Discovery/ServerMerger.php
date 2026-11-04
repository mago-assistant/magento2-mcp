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
        $kept = [
            'enabled' => (bool)($existing['enabled'] ?? false),
            'read_only' => (bool)($existing['read_only'] ?? true),
            'output_public' => (bool)($existing['output_public'] ?? false),
            'missing' => false,
            'last_error' => $existing['last_error'] ?? null,
        ];
        // The admin approved a server as a particular command or URL. When the source now yields another
        // one under the same name (a package update, an edited .mcp.json, a package that took the name of
        // an .mcp.json entry), that approval does not carry over: the row goes back to disabled,
        // read-only and with the privacy filter on, with the reason on it, until an admin has seen the
        // new details.
        $changed = self::connectionChange($server, $existing);
        if ($changed !== null) {
            $kept['enabled'] = false;
            $kept['read_only'] = true;
            $kept['output_public'] = false;
            $kept['last_error'] = 'Connection details changed by a rescan (' . $changed . '); review and enable again.';
        }
        // The allowed-tool list is the administrator's too: a rescan that restored the source's list would
        // put a tool the admin removed back into the catalog. The source's list counts when the row is inserted.
        if (array_key_exists('allowed_tools', $existing) && is_array($existing['allowed_tools'])) {
            $kept['allowed_tools'] = $existing['allowed_tools'];
        }

        return $kept + $server->toRow();
    }

    /**
     * The connection fields that differ between the stored row and the rescanned server, as text, or
     * null when none does. A field the stored row does not carry at all is not compared.
     *
     * @param array<string,mixed> $existing
     */
    public static function connectionChange(DiscoveredServer $server, array $existing): ?string
    {
        $fresh = $server->toRow();
        $changed = [];
        foreach (['source', 'transport', 'command', 'env', 'cwd', 'url', 'auth_type'] as $field) {
            if (!array_key_exists($field, $existing)) {
                continue;
            }
            if (self::normalise($existing[$field]) !== self::normalise($fresh[$field] ?? null)) {
                $changed[] = $field;
            }
        }

        return $changed === [] ? null : implode(', ', $changed);
    }

    private static function normalise(mixed $value): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }

            return (string)json_encode(array_map(static fn ($v) => is_scalar($v) ? (string)$v : $v, $value));
        }

        return $value === null ? '' : (string)$value;
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
