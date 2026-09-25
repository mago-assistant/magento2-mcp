<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use MagoAssistant\Mcp\Service\OAuth\TokenRepository;

final class InMemoryTokenRepository extends TokenRepository
{
    /** @var array<string, array{access_token: string, refresh_token: ?string, expires_at: ?int, scope: ?string}> */
    public array $tokens = [];

    public function __construct()
    {
    }

    public function find(int $adminUserId, string $serverCode): ?array
    {
        return $this->tokens[$adminUserId . ':' . $serverCode] ?? null;
    }

    public function save(int $adminUserId, string $serverCode, array $token): void
    {
        $expiresIn = $token['expires_in'] ?? null;
        $this->tokens[$adminUserId . ':' . $serverCode] = [
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_at' => $expiresIn !== null ? time() + $expiresIn : null,
            'scope' => $token['scope'] ?? null,
        ];
    }

    public function delete(int $adminUserId, string $serverCode): void
    {
        unset($this->tokens[$adminUserId . ':' . $serverCode]);
    }
}
