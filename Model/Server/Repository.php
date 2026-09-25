<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Model\Server;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;
use MagoAssistant\Mcp\Service\Discovery\ServerMerger;

class Repository implements ServerRepositoryInterface
{
    private const TABLE = 'mago_mcp_server';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Json $json,
        private readonly ServerMerger $merger
    ) {
    }

    public function getAll(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from($this->table())->order('name ASC');

        return array_map([$this, 'decode'], $connection->fetchAll($select));
    }

    public function getEnabled(): array
    {
        return array_values(array_filter($this->getAll(), static fn (array $row): bool => $row['enabled']));
    }

    public function getByName(string $name): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from($this->table())->where('name = ?', DiscoveredServer::normaliseName($name));
        $row = $connection->fetchRow($select);

        return $row ? $this->decode($row) : null;
    }

    public function save(array $row): void
    {
        $data = [
            'name' => DiscoveredServer::normaliseName((string)$row['name']),
            'command' => $this->json->serialize(array_values($row['command'] ?? [])),
            'env' => $this->json->serialize($row['env'] ?? []),
            'cwd' => $row['cwd'] ?? null,
            'source' => $row['source'] ?? DiscoveredServer::SOURCE_MANUAL,
            'enabled' => (int)(bool)($row['enabled'] ?? false),
            'missing' => (int)(bool)($row['missing'] ?? false),
            'last_error' => $row['last_error'] ?? null,
        ];
        $this->resourceConnection->getConnection()->insertOnDuplicate(
            $this->table(),
            $data,
            ['command', 'env', 'cwd', 'source', 'enabled', 'missing', 'last_error']
        );
    }

    public function setEnabled(string $name, bool $enabled): void
    {
        $this->update($name, ['enabled' => (int)$enabled]);
    }

    public function setLastError(string $name, ?string $error): void
    {
        $this->update($name, ['last_error' => $error]);
    }

    public function merge(array $discovered): array
    {
        $existing = [];
        foreach ($this->getAll() as $row) {
            $existing[$row['name']] = $row;
        }
        $plan = $this->merger->plan($existing, $discovered);
        foreach ($plan['insert'] as $server) {
            $this->save([
                'name' => $server->name,
                'command' => $server->command,
                'env' => $server->env,
                'cwd' => $server->cwd,
                'source' => $server->source,
                'enabled' => false,
            ]);
        }
        foreach ($plan['update'] as $server) {
            $this->update($server->name, [
                'command' => $this->json->serialize($server->command),
                'env' => $this->json->serialize($server->env),
                'cwd' => $server->cwd,
                'source' => $server->source,
                'missing' => 0,
            ]);
        }
        foreach ($plan['missing'] as $name) {
            $this->update($name, ['missing' => 1]);
        }

        return [
            'inserted' => count($plan['insert']),
            'updated' => count($plan['update']),
            'missing' => count($plan['missing']),
        ];
    }

    /**
     * The name is normalised as save() and getByName() normalise it, so every set*() acts on the stored row.
     *
     * @param array<string,mixed> $data
     */
    private function update(string $name, array $data): void
    {
        $this->resourceConnection->getConnection()->update(
            $this->table(),
            $data,
            ['name = ?' => DiscoveredServer::normaliseName($name)]
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decode(array $row): array
    {
        $row['server_id'] = (int)$row['server_id'];
        $row['command'] = $this->decodeList($row['command'] ?? null);
        $row['env'] = $this->decodeMap($row['env'] ?? null);
        $row['enabled'] = (bool)$row['enabled'];
        $row['missing'] = (bool)$row['missing'];

        return $row;
    }

    /** @return string[] */
    private function decodeList(?string $json): array
    {
        $value = $json === null || $json === '' ? [] : $this->json->unserialize($json);
        $list = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_scalar($item)) {
                $list[] = (string)$item;
            }
        }

        return $list;
    }

    /** @return array<string,string> */
    private function decodeMap(?string $json): array
    {
        $value = $json === null || $json === '' ? [] : $this->json->unserialize($json);
        $map = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_scalar($item)) {
                $map[(string)$key] = (string)$item;
            }
        }

        return $map;
    }

    private function table(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
