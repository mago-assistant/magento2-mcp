<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Model\Server;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;
use MagoAssistant\Mcp\Service\Discovery\NameMigration;
use MagoAssistant\Mcp\Service\Discovery\ServerMerger;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;

class Repository implements ServerRepositoryInterface
{
    private const TABLE = 'mago_mcp_server';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Json $json,
        private readonly ServerMerger $merger,
        private readonly EncryptorInterface $encryptor
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
            'label' => isset($row['label']) && $row['label'] !== '' ? (string)$row['label'] : null,
            'transport' => (string)($row['transport'] ?? ServerConfig::TRANSPORT_STDIO),
            'url' => isset($row['url']) && $row['url'] !== '' ? (string)$row['url'] : null,
            'auth_type' => (string)($row['auth_type'] ?? ServerConfig::AUTH_NONE),
            'allowed_tools' => $this->json->serialize(array_values($row['allowed_tools'] ?? [])),
            'timeout' => isset($row['timeout']) && (int)$row['timeout'] > 0 ? (int)$row['timeout'] : null,
            'output_public' => (int)(bool)($row['output_public'] ?? false),
            'replaces_skill' => isset($row['replaces_skill']) && $row['replaces_skill'] !== ''
                ? (string)$row['replaces_skill']
                : null,
            'source' => $row['source'] ?? DiscoveredServer::SOURCE_MANUAL,
            'enabled' => (int)(bool)($row['enabled'] ?? false),
            'missing' => (int)(bool)($row['missing'] ?? false),
            'last_error' => $row['last_error'] ?? null,
        ];
        if (array_key_exists('bearer_token', $row)) {
            $data['bearer_token'] = (string)$row['bearer_token'] !== ''
                ? $this->encryptor->encrypt((string)$row['bearer_token'])
                : null;
        }
        $update = ['command', 'env', 'cwd', 'label', 'transport', 'url', 'auth_type', 'allowed_tools', 'timeout',
            'output_public', 'replaces_skill', 'source', 'enabled', 'missing', 'last_error'];
        if (array_key_exists('bearer_token', $data)) {
            $update[] = 'bearer_token';
        }
        $this->resourceConnection->getConnection()->insertOnDuplicate($this->table(), $data, $update);
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
            $this->save($server->toRow() + ['enabled' => false]);
        }
        foreach ($plan['update'] as $server) {
            // save() is an upsert: every storable column follows the source, the row keeps its state.
            $this->save($server->toRow() + [
                'missing' => false,
                'enabled' => (bool)($existing[$server->name]['enabled'] ?? false),
                'last_error' => $existing[$server->name]['last_error'] ?? null,
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

    public function migrateLegacyNames(): array
    {
        $plan = NameMigration::plan($this->getAll());
        $connection = $this->resourceConnection->getConnection();
        // Raw names on purpose: these rows are exactly the ones a normalised lookup cannot reach.
        foreach ($plan['delete'] as $name) {
            $connection->delete($this->table(), ['name = ?' => $name]);
        }
        foreach ($plan['rename'] as $old => $new) {
            $connection->update($this->table(), ['name' => $new], ['name = ?' => $old]);
        }

        return ['renamed' => count($plan['rename']), 'deleted' => count($plan['delete'])];
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
        $row['label'] = (string)($row['label'] ?? '');
        $row['transport'] = (string)($row['transport'] ?? ServerConfig::TRANSPORT_STDIO);
        $row['url'] = (string)($row['url'] ?? '');
        $row['auth_type'] = (string)($row['auth_type'] ?? ServerConfig::AUTH_NONE);
        $row['allowed_tools'] = $this->decodeList($row['allowed_tools'] ?? null);
        $row['timeout'] = isset($row['timeout']) && (int)$row['timeout'] > 0 ? (int)$row['timeout'] : null;
        $row['output_public'] = (bool)($row['output_public'] ?? false);
        $row['replaces_skill'] = (string)($row['replaces_skill'] ?? '');
        $row['enabled'] = (bool)$row['enabled'];
        $row['missing'] = (bool)$row['missing'];
        $row['bearer_token'] = isset($row['bearer_token']) && $row['bearer_token'] !== ''
            ? $this->encryptor->decrypt((string)$row['bearer_token'])
            : '';

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
