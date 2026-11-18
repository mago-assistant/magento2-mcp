<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Discovery;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;
use MagoAssistant\Mcp\Service\Discovery\ServerScanner;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServerScannerTest extends TestCase
{
    private FakeLogger $log;

    private function scanner(array $module, array $composer, array $json, bool $scanJson): ServerScanner
    {
        $this->log = new FakeLogger();

        return new ServerScanner(
            [
                'module' => new FakeSource($module),
                'composer' => new FakeSource($composer),
                'mcp_json' => new FakeSource($json),
            ],
            new Config(new FakeScopeConfig(['mago/mcp/scan_mcp_json' => $scanJson ? '1' : '0'])),
            new ErrorLogger($this->log, new Json())
        );
    }

    #[Test]
    public function moduleDefinitionsWinAndDuplicatesAreLogged(): void
    {
        $fromModule = new DiscoveredServer(
            'remote',
            [],
            [],
            null,
            DiscoveredServer::SOURCE_MODULE,
            transport: 'http',
            url: 'https://a.test/mcp'
        );
        $fromJson = new DiscoveredServer(
            'remote',
            [],
            [],
            null,
            DiscoveredServer::SOURCE_MCP_JSON,
            transport: 'http',
            url: 'https://b.test/mcp'
        );

        $servers = $this->scanner([$fromModule], [], [$fromJson], true)->scan();

        self::assertSame([$fromModule], $servers);
        self::assertStringContainsString('"name":"remote"', $this->log->messages());
        self::assertStringContainsString('mcp_json', $this->log->messages());
    }

    #[Test]
    public function composerWinsOnNameCollision(): void
    {
        $fromComposer = new DiscoveredServer('widget', ['php', 'vendor/bin/widget-mcp'], [], null, DiscoveredServer::SOURCE_COMPOSER);
        $fromJson = new DiscoveredServer('widget', ['php', '/var/www/magento/vendor/bin/widget-mcp'], [], '/var/www/magento', DiscoveredServer::SOURCE_MCP_JSON);
        $other = new DiscoveredServer('other', ['node', 'x.js'], [], null, DiscoveredServer::SOURCE_MCP_JSON);

        $servers = $this->scanner([], [$fromComposer], [$fromJson, $other], true)->scan();

        self::assertSame(['widget', 'other'], array_map(static fn ($s) => $s->name, $servers));
        self::assertSame(DiscoveredServer::SOURCE_COMPOSER, $servers[0]->source);
    }

    #[Test]
    public function skipsMcpJsonWhenDisabledInConfig(): void
    {
        $other = new DiscoveredServer('other', ['node', 'x.js'], [], null, DiscoveredServer::SOURCE_MCP_JSON);

        self::assertSame([], $this->scanner([], [], [$other], false)->scan());
    }
}
