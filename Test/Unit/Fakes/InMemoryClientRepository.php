<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use MagoAssistant\Mcp\Service\OAuth\ClientRepository;

final class InMemoryClientRepository extends ClientRepository
{
    /** @var array<string, array{server_url: string, redirect_uri: string, client_id: string, client_secret: ?string, metadata: array<string, string>}> */
    public array $clients = [];

    public function __construct()
    {
    }

    public function find(string $serverCode): ?array
    {
        return $this->clients[$serverCode] ?? null;
    }

    public function save(string $serverCode, array $client): void
    {
        $this->clients[$serverCode] = $client;
    }

    public function delete(string $serverCode): void
    {
        unset($this->clients[$serverCode]);
    }
}
