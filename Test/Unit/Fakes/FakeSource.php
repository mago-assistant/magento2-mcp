<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;
use MagoAssistant\Mcp\Service\Discovery\SourceInterface;

final class FakeSource implements SourceInterface
{
    /**
     * @param DiscoveredServer[] $servers
     */
    public function __construct(private readonly array $servers)
    {
    }

    public function discover(): array
    {
        return $this->servers;
    }
}
