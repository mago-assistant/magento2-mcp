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
        self::assertSame(['mine'], $plan['skipped'], 'a discovered server that met a manual row is reported, not silently dropped');
    }

    #[Test]
    public function aHyphenatedDiscoveryMeetsItsUnderscoreRowAsAnUpdate(): void
    {
        $existing = ['my_server' => $this->row('my_server', DiscoveredServer::SOURCE_MCP_JSON)];
        $again = new DiscoveredServer(
            DiscoveredServer::normaliseName('my-server'),
            ['node', 'x.js'],
            [],
            null,
            DiscoveredServer::SOURCE_MCP_JSON
        );

        $plan = (new ServerMerger())->plan($existing, [$again]);

        self::assertSame([], $plan['insert'], 'the hyphen form is the same server, not a new row');
        self::assertSame([$again], $plan['update']);
        self::assertSame([], $plan['missing']);
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

    #[Test]
    public function aRescanKeepsTheAdminsTrustSettings(): void
    {
        $existing = ['name' => 'docs', 'enabled' => true, 'read_only' => true, 'output_public' => true, 'last_error' => 'x'];
        $again = new DiscoveredServer('docs', [], [], null, DiscoveredServer::SOURCE_MCP_JSON, url: 'https://new.test/mcp');

        $row = ServerMerger::updatedRow($again, $existing);

        self::assertSame('https://new.test/mcp', $row['url'], 'connection details follow the source');
        self::assertTrue($row['enabled']);
        self::assertTrue($row['read_only'], 'the admin declared it read-only; a rescan must not undo that');
        self::assertTrue($row['output_public']);
        self::assertFalse($row['missing']);
        self::assertSame('x', $row['last_error']);
    }
}
