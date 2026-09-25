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

        self::assertCount(2, $servers);
        self::assertSame('widget', $servers[0]->name, 'the magento- prefix is stripped like the Composer name');
        self::assertSame(['php', 'bin/x-mcp'], $servers[0]->command);
        self::assertSame('/app', $servers[0]->cwd);
        self::assertSame(DiscoveredServer::SOURCE_MCP_JSON, $servers[0]->source);
        self::assertSame(['node', 'srv.js'], $servers[1]->command);
        self::assertSame(['K' => 'v'], $servers[1]->env);
        self::assertSame([], $this->log->entries);
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
}
