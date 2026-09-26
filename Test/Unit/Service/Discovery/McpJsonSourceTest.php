<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Discovery;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;
use MagoAssistant\Mcp\Service\Discovery\DockerExecUnwrapper;
use MagoAssistant\Mcp\Service\Discovery\McpJsonSource;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class McpJsonSourceTest extends TestCase
{
    private string $root;
    private FakeLogger $log;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mago-mcp-' . uniqid();
        mkdir($this->root);
        $this->log = new FakeLogger();
    }

    protected function tearDown(): void
    {
        if (is_file($this->root . '/.mcp.json')) {
            unlink($this->root . '/.mcp.json');
        }
        rmdir($this->root);
    }

    private function source(): McpJsonSource
    {
        return new McpJsonSource(
            new DirectoryList($this->root),
            new File(),
            new DockerExecUnwrapper(),
            new ErrorLogger($this->log, new Json())
        );
    }

    #[Test]
    public function readsStdioEntriesAndUnwrapsDocker(): void
    {
        file_put_contents($this->root . '/.mcp.json', json_encode(['mcpServers' => [
            'Magento Widget' => ['command' => 'docker', 'args' => ['exec', '-i', '-w', '/app', 'php', 'php', 'bin/x-mcp']],
            'plain' => ['command' => 'node', 'args' => ['srv.js'], 'env' => ['K' => 'v']],
            'remote' => ['type' => 'http', 'url' => 'https://example.test/mcp'],
        ]]));

        $servers = $this->source()->discover();

        self::assertCount(3, $servers);
        self::assertSame('widget', $servers[0]->name, 'the magento- prefix is stripped like the Composer name');
        self::assertSame(['php', 'bin/x-mcp'], $servers[0]->command);
        self::assertSame('/app', $servers[0]->cwd);
        self::assertSame(DiscoveredServer::SOURCE_MCP_JSON, $servers[0]->source);
        self::assertSame(['node', 'srv.js'], $servers[1]->command);
        self::assertSame(['K' => 'v'], $servers[1]->env);
        self::assertSame('http', $servers[2]->transport);
        self::assertSame([], $this->log->entries);
    }

    #[Test]
    public function anHttpEntryWithABearerHeaderBecomesABearerRow(): void
    {
        file_put_contents($this->root . '/.mcp.json', json_encode(['mcpServers' => [
            'Remote Thing' => [
                'type' => 'http',
                'url' => 'https://example.test/mcp',
                'headers' => ['Authorization' => 'Bearer s3cret', 'X-Other' => 'x'],
            ],
            'plain-url' => ['url' => 'https://plain.test/mcp'],
            'streamable' => ['type' => 'streamable-http', 'url' => 'https://s.test/mcp'],
        ]]));

        $servers = $this->source()->discover();

        self::assertSame(['remote_thing', 'plain_url', 'streamable'], array_map(static fn ($s) => $s->name, $servers));
        self::assertSame('http', $servers[0]->transport);
        self::assertSame([], $servers[0]->command);
        self::assertSame('https://example.test/mcp', $servers[0]->url);
        self::assertSame('bearer', $servers[0]->authType);
        self::assertSame('s3cret', $servers[0]->bearerToken);
        self::assertSame('none', $servers[1]->authType);
        self::assertSame('', $servers[1]->bearerToken);
        self::assertStringContainsString('X-Other', $this->log->messages(), 'an unsupported header is logged');
        self::assertStringNotContainsString('s3cret', $this->log->messages(), 'the token itself is never logged');
    }

    #[Test]
    public function anSseEntryIsSkippedAndLogged(): void
    {
        file_put_contents($this->root . '/.mcp.json', json_encode(['mcpServers' => [
            'old' => ['type' => 'sse', 'url' => 'https://example.test/sse'],
        ]]));

        self::assertSame([], $this->source()->discover());
        self::assertStringContainsString('sse', $this->log->messages());
    }

    #[Test]
    public function missingFileYieldsNothing(): void
    {
        self::assertSame([], $this->source()->discover());
    }

    #[Test]
    public function malformedFileYieldsNothing(): void
    {
        file_put_contents($this->root . '/.mcp.json', '{not json');
        self::assertSame([], $this->source()->discover());

        file_put_contents($this->root . '/.mcp.json', '{"servers": {}}');
        self::assertSame([], $this->source()->discover());
        self::assertCount(2, $this->log->entries);
        self::assertStringContainsString('.mcp.json', $this->log->messages());
    }

    #[Test]
    public function aPlaceholderTokenIsExpandedFromTheEnvironment(): void
    {
        putenv('MAGO_TEST_MCP_TOKEN=abc123');
        try {
            file_put_contents($this->root . '/.mcp.json', json_encode(['mcpServers' => [
                'remote' => ['url' => 'https://example.test/mcp', 'headers' => ['Authorization' => 'Bearer ${MAGO_TEST_MCP_TOKEN}']],
            ]]));

            $servers = $this->source()->discover();
        } finally {
            putenv('MAGO_TEST_MCP_TOKEN');
        }

        self::assertSame('bearer', $servers[0]->authType);
        self::assertSame('abc123', $servers[0]->bearerToken);
    }

    #[Test]
    public function anUnresolvablePlaceholderTokenIsSkipped(): void
    {
        file_put_contents($this->root . '/.mcp.json', json_encode(['mcpServers' => [
            'remote' => ['url' => 'https://example.test/mcp', 'headers' => ['Authorization' => 'Bearer ${MAGO_TEST_MCP_UNSET}']],
        ]]));

        $servers = $this->source()->discover();

        self::assertSame('none', $servers[0]->authType, 'a literal placeholder would 401 on every page for five minutes');
        self::assertSame('', $servers[0]->bearerToken);
        self::assertStringContainsString('MAGO_TEST_MCP_UNSET', $this->log->messages());
    }

    #[Test]
    public function aNonStringUrlSkipsTheEntryWithALogLine(): void
    {
        file_put_contents($this->root . '/.mcp.json', json_encode(['mcpServers' => [
            'broken' => ['url' => ['not', 'a', 'string']],
            'fine' => ['url' => 'https://example.test/mcp'],
        ]]));

        $servers = $this->source()->discover();

        self::assertSame(['fine'], array_map(static fn ($s) => $s->name, $servers));
        self::assertStringContainsString('broken', $this->log->messages());
    }
}
