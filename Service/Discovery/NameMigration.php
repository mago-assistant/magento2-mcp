<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Discovery;

/**
 * What to do with rows stored under a name an earlier version normalised differently ("n98-magerun2",
 * "magento_widget"): every lookup normalises the name it is given, so such a row can neither be called
 * nor disabled, and a rescan would insert its twin. Each row is renamed to today's form; where two rows
 * land on one name, an enabled row wins, then the one already carrying the name, then the first.
 */
final class NameMigration
{
    /**
     * @param array<int,array<string,mixed>> $rows decoded rows with at least name and enabled
     * @return array{rename: array<string,string>, delete: string[]} old name => new name, and old names to drop
     */
    public static function plan(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $name = (string)$row['name'];
            $groups[DiscoveredServer::normaliseName($name)][] = ['name' => $name, 'enabled' => (bool)($row['enabled'] ?? false)];
        }
        $rename = [];
        $delete = [];
        foreach ($groups as $target => $members) {
            $keeper = self::keeper((string)$target, $members);
            foreach ($members as $member) {
                if ($member['name'] !== $keeper) {
                    $delete[] = $member['name'];
                }
            }
            if ($keeper !== (string)$target) {
                $rename[$keeper] = (string)$target;
            }
        }

        return ['rename' => $rename, 'delete' => $delete];
    }

    /**
     * @param array<int,array{name:string,enabled:bool}> $members
     */
    private static function keeper(string $target, array $members): string
    {
        foreach ([true, false] as $enabled) {
            foreach ($members as $member) {
                if ($member['enabled'] === $enabled && $member['name'] === $target) {
                    return $member['name'];
                }
            }
            foreach ($members as $member) {
                if ($member['enabled'] === $enabled) {
                    return $member['name'];
                }
            }
        }

        return $members[0]['name'];
    }
}
