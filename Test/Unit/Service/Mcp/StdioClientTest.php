<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Mcp;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as MagoConfig;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mcp\Service\Mcp\McpException;
use MagoAssistant\Mcp\Service\Mcp\McpProcessException;
use MagoAssistant\Mcp\Service\Mcp\McpTimeoutException;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use MagoAssistant\Mcp\Service\Mcp\StdioClient;
use MagoAssistant\Mcp\Service\Mcp\StdioSessionFactory;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;

final class StdioClientTest extends TestCase
{
    private StdioClient $client;
    private FakeLogger $log;
    private StdioSessionFactory $factory;

    protected function setUp(): void
    {
        $this->log = new FakeLogger();
        $magoConfig = $this->createStub(MagoConfig::class);
        $magoConfig->method('isDebugEnabled')->willReturn(true);
        $this->factory = new StdioSessionFactory(
            new DirectoryList(dirname(__DIR__, 4)),
            new DebugLogger($this->log, new Json()),
            $magoConfig,
            new PhpExecutableFinder()
        );
        $this->client = new StdioClient($this->factory);
    }

    private function server(array $env = []): ServerConfig
    {
        return new ServerConfig('fake', [PHP_BINARY, dirname(__DIR__, 2) . '/Fakes/fake-mcp-server.php'], $env);
    }

    /**
     * Process ids of fake servers still alive, so leak tests can assert cleanup.
     *
     * @return string[]
     */
    private function liveFakeServers(): array
    {
        $out = [];
        // [f]ake: the pattern must not match the `sh -c` wrapper that runs pgrep, whose own command
        // line contains this string; quoted so the shell never treats the brackets as a glob.
        exec('pgrep -f "[f]ake-mcp-server.php" 2>/dev/null', $out);

        return $out;
    }

    #[Test]
    public function listToolsFollowsPaginationAndKeepsServerInfoAndInstructions(): void
    {
        $result = $this->client->listTools($this->server(['FAKE_MCP_INSTRUCTIONS' => 'Be brief.']), 10);

        self::assertSame(['echo-args', 'item-delete', 'slow-get', 'image-get', 'weather', 'stock-get'], array_column($result['tools'], 'name'));
        self::assertSame(['text'], $result['tools'][0]['inputSchema']['required']);
        self::assertSame('fake-mcp', $result['serverInfo']['name']);
        self::assertSame('Be brief.', $result['instructions']);
    }

    #[Test]
    public function instructionsDefaultToEmptyString(): void
    {
        self::assertSame('', $this->client->listTools($this->server(), 10)['instructions']);
    }

    #[Test]
    public function callToolReturnsContentAndAnswersServerPingAndSkipsNotifications(): void
    {
        $result = $this->client->callTool($this->server(), 'echo-args', ['text' => 'hi'], 10);

        self::assertFalse($result['isError']);
        self::assertSame('{"text":"hi"}', $result['content'][0]['text']);
    }

    #[Test]
    public function serverErrorBecomesMcpException(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Unknown tool: nope');

        $this->client->callTool($this->server(), 'nope', [], 10);
    }

    #[Test]
    public function isErrorResultPassesThrough(): void
    {
        $result = $this->client->callTool($this->server(), 'item-delete', ['id' => 1], 10);

        self::assertTrue($result['isError']);
        self::assertSame('Item not found', $result['content'][0]['text']);
    }

    #[Test]
    public function timeoutKillsTheProcessImmediately(): void
    {
        $start = microtime(true);
        try {
            $this->client->callTool($this->server(), 'slow-get', [], 1);
            self::fail('expected timeout');
        } catch (McpTimeoutException $e) {
            self::assertStringContainsString('timed out after 1 s', $e->getMessage());
        }
        self::assertLessThan(2.5, microtime(true) - $start);
        self::assertSame([], $this->liveFakeServers());
    }

    #[Test]
    public function crashBeforeAnswerReportsStderr(): void
    {
        try {
            $this->client->listTools($this->server(['FAKE_MCP_CRASH' => '1']), 10);
            self::fail('expected process error');
        } catch (McpProcessException $e) {
            self::assertStringContainsString('boom', $e->getMessage());
        }
        self::assertStringContainsString('boom: fake crash', $this->log->messages(), 'stderr reaches the debug log');
    }

    #[Test]
    public function missingBinaryIsAProcessError(): void
    {
        $this->expectException(McpProcessException::class);

        $this->client->listTools(new ServerConfig('gone', ['/nonexistent/mcp-binary']), 5);
    }

    #[Test]
    public function rejectsUnsupportedProtocolVersion(): void
    {
        try {
            $this->client->listTools($this->server(['FAKE_MCP_VERSION' => '2099-01-01']), 10);
            self::fail('expected version rejection');
        } catch (McpException $e) {
            self::assertStringContainsString('2099-01-01', $e->getMessage());
        }
        self::assertSame([], $this->liveFakeServers(), 'the process is closed when the handshake fails');
    }

    #[Test]
    public function acceptsOlderSupportedProtocolVersion(): void
    {
        self::assertCount(6, $this->client->listTools($this->server(['FAKE_MCP_VERSION' => '2025-03-26']), 10)['tools']);
    }

    #[Test]
    public function barePhpIsResolvedToTheCliBinary(): void
    {
        $resolved = $this->factory->resolve(new ServerConfig('x', ['php', 'a.php']));

        self::assertStringStartsWith('/', $resolved->command[0]);
        self::assertSame('a.php', $resolved->command[1]);
        self::assertSame(['node', 'x'], $this->factory->resolve(new ServerConfig('y', ['node', 'x']))->command);
    }

    #[Test]
    public function handshakeFailureClosesTheProcess(): void
    {
        $sleeper = new ServerConfig('sleeper', ['sleep', '8']);
        try {
            $this->client->listTools($sleeper, 1);
            self::fail('expected timeout');
        } catch (McpTimeoutException) {
        }
        $out = [];
        exec('pgrep -f "^sleep 8$" 2>/dev/null', $out);
        self::assertSame([], $out, 'no orphaned server after a handshake timeout');
    }
}
