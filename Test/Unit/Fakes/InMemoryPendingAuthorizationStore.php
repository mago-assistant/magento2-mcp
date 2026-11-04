<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use MagoAssistant\Mcp\Service\OAuth\PendingAuthorizationStore;

final class InMemoryPendingAuthorizationStore extends PendingAuthorizationStore
{
    /** @var array<string, array{server: string, user: int, verifier: string, created: int}> */
    public array $pending = [];

    public function __construct()
    {
    }

    public function all(): array
    {
        return $this->pending;
    }

    public function replace(array $pending): void
    {
        $this->pending = $pending;
    }
}
