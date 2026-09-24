<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Discovery;

use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;
use MagoAssistant\Mcp\Service\Discovery\ServerScanner;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServerScannerTest extends TestCase
{
    private function scanner(array $composer, array $json, bool $scanJson): ServerScanner
    {
        return new ServerScanner(
            new FakeSource($composer),
            new FakeSource($json),
            new Config(new FakeScopeConfig(['mago/mcp/scan_mcp_json' => $scanJson ? '1' : '0']))
        );
    }

    #[Test]
    public function composerWinsOnNameCollision(): void
    {
        $fromComposer = new DiscoveredServer('bricklayer', ['php', 'vendor/bin/bricklayer-mcp'], [], null, DiscoveredServer::SOURCE_COMPOSER);
        $fromJson = new DiscoveredServer('bricklayer', ['php', '/var/www/magento/vendor/bin/bricklayer-mcp'], [], '/var/www/magento', DiscoveredServer::SOURCE_MCP_JSON);
        $other = new DiscoveredServer('other', ['node', 'x.js'], [], null, DiscoveredServer::SOURCE_MCP_JSON);

        $servers = $this->scanner([$fromComposer], [$fromJson, $other], true)->scan();

        self::assertSame(['bricklayer', 'other'], array_map(static fn ($s) => $s->name, $servers));
        self::assertSame(DiscoveredServer::SOURCE_COMPOSER, $servers[0]->source);
    }

    #[Test]
    public function skipsMcpJsonWhenDisabledInConfig(): void
    {
        $other = new DiscoveredServer('other', ['node', 'x.js'], [], null, DiscoveredServer::SOURCE_MCP_JSON);

        self::assertSame([], $this->scanner([], [$other], false)->scan());
    }
}
