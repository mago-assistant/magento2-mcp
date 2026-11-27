<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Mcp\Channel;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as MagoConfig;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Mcp\Channel\ChannelFactory;
use MagoAssistant\Mcp\Service\Mcp\McpProcessException;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use MagoAssistant\Mcp\Service\Mcp\StdioSessionFactory;
use MagoAssistant\Mcp\Service\Privacy\SecretScrub;
use MagoAssistant\Mcp\Service\Transport\StdioTransport;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use MagoAssistant\Mcp\Test\Unit\Fakes\RunsRelay;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * A web request (any SAPI but cli) reaches a local server through a real worker.
 */
final class WorkerChannelTest extends TestCase
{
    use RunsRelay;

    private const CRYPT_KEY = 'shop-crypt-key';

    private string $address = '';

    protected function setUp(): void
    {
        $this->address = $this->startRelay([
            'fake' => ['command' => self::fakeServerCommand(), 'timeout' => 10],
            'crashing' => ['command' => self::fakeServerCommand(), 'env' => ['FAKE_MCP_CRASH' => '1'], 'timeout' => 10],
        ], self::CRYPT_KEY);
    }

    protected function tearDown(): void
    {
        $this->stopRelay();
    }

    private function transport(string $address, string $cryptKey = self::CRYPT_KEY, string $sapi = 'fpm-fcgi'): StdioTransport
    {
        $magoConfig = $this->createStub(MagoConfig::class);
        $magoConfig->method('isDebugEnabled')->willReturn(false);
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturn($cryptKey);

        return new StdioTransport(new StdioSessionFactory(
            new DirectoryList(sys_get_temp_dir()),
            new DebugLogger(new FakeLogger(), new Json(), $magoConfig),
            $magoConfig,
            new PhpExecutableFinder(),
            new SecretScrub(),
            new ChannelFactory(new Config(new FakeScopeConfig([Config::XML_PATH_WORKER_ADDRESS => $address])), $deployment, $sapi)
        ));
    }

    private function row(string $name): ServerConfig
    {
        // The web side sends only the name; this command must never run here.
        return new ServerConfig($name, ['/nonexistent/must-not-run'], timeout: 10);
    }

    #[Test]
    public function listsToolsThroughTheWorker(): void
    {
        $result = $this->transport($this->address)->listTools($this->row('fake'));

        self::assertSame(['echo-args', 'item-delete', 'slow-get', 'image-get', 'weather', 'stock-get'], array_column($result['tools'], 'name'));
        self::assertSame('fake-mcp', $result['serverInfo']['name']);
    }

    #[Test]
    public function callsAToolThroughTheWorker(): void
    {
        $result = $this->transport($this->address)->callTool($this->row('fake'), 'echo-args', ['text' => 'via worker']);

        self::assertSame('{"text":"via worker"}', $result['content'][0]['text']);
    }

    #[Test]
    public function aServerThatExitsIsReportedWithItsCodeAndStderr(): void
    {
        $this->expectException(McpProcessException::class);
        $this->expectExceptionMessage('MCP server "crashing" exited (code 3) before answering: boom: fake crash');

        $this->transport($this->address)->listTools($this->row('crashing'));
    }

    #[Test]
    public function noWorkerGivesAClearMessage(): void
    {
        try {
            $this->transport('127.0.0.1:1')->listTools($this->row('fake'));
            self::fail('expected an McpProcessException');
        } catch (McpProcessException $e) {
            self::assertStringContainsString('MCP worker', $e->getMessage());
            self::assertStringContainsString('127.0.0.1:1', $e->getMessage());
            self::assertStringContainsString('bin/magento mago:mcp:serve', $e->getMessage());
        }
    }

    #[Test]
    public function theWorkersRefusalReachesTheAdmin(): void
    {
        $this->expectException(McpProcessException::class);
        $this->expectExceptionMessage('"unknown" is not an enabled local MCP server.');

        $this->transport($this->address)->listTools($this->row('unknown'));
    }

    #[Test]
    public function anotherInstallsKeyIsRefused(): void
    {
        $this->expectException(McpProcessException::class);
        $this->expectExceptionMessage('not authorised');

        $this->transport($this->address, 'another-shop')->listTools($this->row('fake'));
    }

    #[Test]
    public function aProcessOnTheWorkersPortThatCannotProveItselfGetsNothing(): void
    {
        $received = $this->relayDir . '/impostor-received';
        $pipes = [];
        $impostor = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/Fakes/impostor-worker.php', $received], [1 => ['pipe', 'w']], $pipes);
        $address = trim((string)fgets($pipes[1]));
        try {
            $this->transport($address)->callTool($this->row('fake'), 'echo-args', ['text' => 'customer data']);
            self::fail('expected an McpProcessException');
        } catch (McpProcessException $e) {
            self::assertStringContainsString('could not prove', $e->getMessage());
        } finally {
            usleep(300000);
            proc_terminate($impostor, 9);
            proc_close($impostor);
        }
        self::assertSame('', is_file($received) ? (string)file_get_contents($received) : '', 'no JSON-RPC byte was sent');
    }

    #[Test]
    public function statusTellsAnImpostorOrAnotherKeyFromNoWorker(): void
    {
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturn('another-shop');
        $factory = new ChannelFactory(new Config(new FakeScopeConfig([Config::XML_PATH_WORKER_ADDRESS => $this->address])), $deployment, 'cli');

        self::assertSame(['state' => 'refused'], $factory->workerClient()->status());
        self::assertNull((new ChannelFactory(new Config(new FakeScopeConfig([Config::XML_PATH_WORKER_ADDRESS => '127.0.0.1:1'])), $deployment, 'cli'))->workerClient()->status());
    }

    #[Test]
    public function theCommandLineStillStartsTheServerItself(): void
    {
        $server = new ServerConfig('fake', self::fakeServerCommand(), timeout: 10);

        $result = $this->transport('127.0.0.1:1', sapi: 'cli')->listTools($server);

        self::assertSame('fake-mcp', $result['serverInfo']['name']);
    }
}
