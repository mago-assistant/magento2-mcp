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
        $existing = ['name' => 'docs', 'enabled' => true, 'read_only' => true, 'output_public' => true, 'last_error' => 'x',
            'allowed_tools' => ['query-docs'], 'source' => 'mcp_json', 'transport' => 'http', 'command' => [], 'env' => [],
            'cwd' => null, 'url' => 'https://docs.test/mcp', 'auth_type' => 'none'];
        $again = new DiscoveredServer('docs', [], [], null, DiscoveredServer::SOURCE_MCP_JSON, label: 'Docs', transport: 'http', url: 'https://docs.test/mcp',
            allowedTools: ['query-docs', 'code-runner'], timeout: 30);

        $row = ServerMerger::updatedRow($again, $existing);

        self::assertSame('Docs', $row['label'], 'details that are not the connection follow the source');
        self::assertSame(30, $row['timeout']);
        self::assertTrue($row['enabled']);
        self::assertTrue($row['read_only'], 'the admin declared it read-only; a rescan must not undo that');
        self::assertTrue($row['output_public']);
        self::assertSame(['query-docs'], $row['allowed_tools'], 'a tool the admin removed does not come back with a rescan');
        self::assertFalse($row['missing']);
        self::assertSame('x', $row['last_error']);
    }

    #[Test]
    public function aRescanThatChangesTheConnectionWithdrawsTheAdminsApproval(): void
    {
        $existing = ['name' => 'tools', 'enabled' => true, 'read_only' => true, 'output_public' => true, 'last_error' => null,
            'source' => 'mcp_json', 'transport' => 'stdio', 'command' => ['php', 'bin/tools-mcp'], 'env' => [], 'cwd' => null,
            'url' => '', 'auth_type' => 'none'];
        $takeover = new DiscoveredServer('tools', ['php', 'vendor/bin/other-mcp'], [], null, DiscoveredServer::SOURCE_COMPOSER);

        $row = ServerMerger::updatedRow($takeover, $existing);

        self::assertSame(['php', 'vendor/bin/other-mcp'], $row['command'], 'the source still owns the connection');
        self::assertFalse($row['enabled'], 'but what the admin approved was another command: back to disabled');
        self::assertTrue($row['read_only'], 'and read-only again');
        self::assertFalse($row['output_public'], 'with the privacy filter on');
        self::assertStringContainsString('source, command', (string)$row['last_error']);

        $sameAgain = new DiscoveredServer('tools', ['php', 'bin/tools-mcp'], [], null, DiscoveredServer::SOURCE_MCP_JSON);
        self::assertNull(ServerMerger::connectionChange($sameAgain, $existing));
        self::assertTrue(ServerMerger::updatedRow($sameAgain, $existing)['enabled']);

        $envChanged = new DiscoveredServer('tools', ['php', 'bin/tools-mcp'], ['LD_PRELOAD' => '/tmp/x.so'], null, DiscoveredServer::SOURCE_MCP_JSON);
        self::assertSame('env', ServerMerger::connectionChange($envChanged, $existing));
    }

    #[Test]
    public function aRowWithoutAnAllowedListTakesTheSources(): void
    {
        $existing = ['name' => 'docs', 'enabled' => true];
        $again = new DiscoveredServer('docs', [], [], null, DiscoveredServer::SOURCE_MODULE, allowedTools: ['a']);

        self::assertSame(['a'], ServerMerger::updatedRow($again, $existing)['allowed_tools']);
    }

    #[Test]
    public function anOauthRowWhoseUrlChangedHasStaleCredentials(): void
    {
        $existing = ['name' => 'docs', 'auth_type' => 'oauth', 'url' => 'https://a.test/mcp'];
        $moved = new DiscoveredServer('docs', [], [], null, DiscoveredServer::SOURCE_MCP_JSON, url: 'https://b.test/mcp', authType: 'oauth');
        $same = new DiscoveredServer('docs', [], [], null, DiscoveredServer::SOURCE_MCP_JSON, url: 'https://a.test/mcp', authType: 'oauth');

        self::assertTrue(ServerMerger::credentialsStale($moved, $existing));
        self::assertFalse(ServerMerger::credentialsStale($same, $existing));
        self::assertFalse(ServerMerger::credentialsStale($moved, ['auth_type' => 'bearer', 'url' => 'https://a.test/mcp']));
    }
}
