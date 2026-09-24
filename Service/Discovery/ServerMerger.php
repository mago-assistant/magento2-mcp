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
     * @param array<string,array<string,mixed>> $existingByName
     * @param DiscoveredServer[] $discovered
     * @return array{insert: DiscoveredServer[], update: DiscoveredServer[], missing: string[]}
     */
    public function plan(array $existingByName, array $discovered): array
    {
        $insert = [];
        $update = [];
        $seen = [];
        foreach ($discovered as $server) {
            $seen[$server->name] = true;
            $row = $existingByName[$server->name] ?? null;
            if ($row === null) {
                $insert[] = $server;
                continue;
            }
            if (($row['source'] ?? '') === DiscoveredServer::SOURCE_MANUAL) {
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

        return ['insert' => $insert, 'update' => $update, 'missing' => $missing];
    }
}
