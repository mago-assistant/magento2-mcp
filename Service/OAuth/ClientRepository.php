<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\OAuth;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * The dynamic client registration (RFC 7591) of this Magento install at each server's authorization server
 */
class ClientRepository
{
    private const TABLE = 'mago_mcp_oauth_client';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EncryptorInterface $encryptor,
        private readonly Json $json
    ) {
    }

    /**
     * @return array{server_url: string, redirect_uri: string, client_id: string, client_secret: ?string, metadata: array<string, string>}|null
     */
    public function find(string $serverCode): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resourceConnection->getTableName(self::TABLE))
                ->where('server_code = ?', $serverCode)
        );
        if (!is_array($row) || $row === []) {
            return null;
        }
        $metadata = $this->json->unserialize((string)$row['metadata']);

        return [
            'server_url' => (string)$row['server_url'],
            'redirect_uri' => (string)$row['redirect_uri'],
            'client_id' => (string)$row['client_id'],
            'client_secret' => $row['client_secret'] !== null && $row['client_secret'] !== ''
                ? $this->encryptor->decrypt((string)$row['client_secret'])
                : null,
            'metadata' => is_array($metadata) ? array_map('strval', $metadata) : [],
        ];
    }

    /**
     * @param string $serverCode
     * @param array{server_url: string, redirect_uri: string, client_id: string, client_secret: ?string, metadata: array<string, string>} $client
     */
    public function save(string $serverCode, array $client): void
    {
        $this->resourceConnection->getConnection()->insertOnDuplicate(
            $this->resourceConnection->getTableName(self::TABLE),
            [
                'server_code' => $serverCode,
                'server_url' => $client['server_url'],
                'redirect_uri' => $client['redirect_uri'],
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'] !== null ? $this->encryptor->encrypt($client['client_secret']) : null,
                'metadata' => (string)$this->json->serialize($client['metadata']),
            ],
            ['server_url', 'redirect_uri', 'client_id', 'client_secret', 'metadata']
        );
    }
}
