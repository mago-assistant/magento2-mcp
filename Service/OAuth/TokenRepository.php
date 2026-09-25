<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\OAuth;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * OAuth tokens per admin user and server. A token is only ever read for the user it belongs to.
 */
class TokenRepository
{
    private const TABLE = 'mago_mcp_token';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_at: ?int, scope: ?string}|null
     */
    public function find(int $adminUserId, string $serverCode): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resourceConnection->getTableName(self::TABLE))
                ->where('admin_user_id = ?', $adminUserId)
                ->where('server_code = ?', $serverCode)
        );
        if (!is_array($row) || $row === []) {
            return null;
        }

        return [
            'access_token' => $this->encryptor->decrypt((string)$row['access_token']),
            'refresh_token' => $row['refresh_token'] !== null && $row['refresh_token'] !== ''
                ? $this->encryptor->decrypt((string)$row['refresh_token'])
                : null,
            'expires_at' => $row['expires_at'] !== null ? (int)strtotime($row['expires_at'] . ' UTC') : null,
            'scope' => $row['scope'] !== null ? (string)$row['scope'] : null,
        ];
    }

    /**
     * @param int $adminUserId
     * @param string $serverCode
     * @param array{access_token: string, refresh_token?: ?string, expires_in?: ?int, scope?: ?string} $token
     */
    public function save(int $adminUserId, string $serverCode, array $token): void
    {
        $expiresIn = $token['expires_in'] ?? null;
        $connection = $this->resourceConnection->getConnection();
        $connection->insertOnDuplicate(
            $this->resourceConnection->getTableName(self::TABLE),
            [
                'admin_user_id' => $adminUserId,
                'server_code' => $serverCode,
                'access_token' => $this->encryptor->encrypt($token['access_token']),
                'refresh_token' => ($token['refresh_token'] ?? null) !== null
                    ? $this->encryptor->encrypt((string)$token['refresh_token'])
                    : null,
                'expires_at' => $expiresIn !== null ? gmdate('Y-m-d H:i:s', time() + $expiresIn) : null,
                'scope' => $token['scope'] ?? null,
            ],
            ['access_token', 'refresh_token', 'expires_at', 'scope']
        );
    }

    public function delete(int $adminUserId, string $serverCode): void
    {
        $this->resourceConnection->getConnection()->delete(
            $this->resourceConnection->getTableName(self::TABLE),
            ['admin_user_id = ?' => $adminUserId, 'server_code = ?' => $serverCode]
        );
    }
}
