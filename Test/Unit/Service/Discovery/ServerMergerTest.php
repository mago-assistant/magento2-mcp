<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Discovery;

use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;
use MagoAssistant\Mcp\Service\Discovery\ServerMerger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServerMergerTest extends TestCase
{
    private function row(string $name, string $source, bool $enabled = true): array
    {
        return ['name' => $name, 'source' => $source, 'enabled' => $enabled, 'command' => ['old'], 'env' => [], 'cwd' => null];
    }

    #[Test]
    public function insertsNewUpdatesKnownAndFlagsVanished(): void
    {
        $existing = [
            'widget' => $this->row('widget', DiscoveredServer::SOURCE_COMPOSER),
            'gone' => $this->row('gone', DiscoveredServer::SOURCE_MCP_JSON),
            'mine' => $this->row('mine', DiscoveredServer::SOURCE_MANUAL),
        ];
        $widget = new DiscoveredServer('widget', ['php', 'vendor/bin/widget-mcp'], [], null, DiscoveredServer::SOURCE_COMPOSER);
        $fresh = new DiscoveredServer('fresh', ['node', 'x.js'], [], null, DiscoveredServer::SOURCE_MCP_JSON);
        $mineAgain = new DiscoveredServer('mine', ['php', 'other'], [], null, DiscoveredServer::SOURCE_COMPOSER);

        $plan = (new ServerMerger())->plan($existing, [$widget, $fresh, $mineAgain]);

        self::assertSame([$fresh], $plan['insert']);
        self::assertSame([$widget], $plan['update'], 'manual rows are never updated by a scan');
        self::assertSame(['gone'], $plan['missing']);
    }

    #[Test]
    public function aVanishedManualRowIsNeverFlaggedMissing(): void
    {
        $existing = ['mine' => $this->row('mine', DiscoveredServer::SOURCE_MANUAL)];

        $plan = (new ServerMerger())->plan($existing, []);

        self::assertSame([], $plan['missing'], 'manual rows are the administrator\'s, a scan cannot lose them');
        self::assertSame([], $plan['insert']);
        self::assertSame([], $plan['update']);
    }
}
