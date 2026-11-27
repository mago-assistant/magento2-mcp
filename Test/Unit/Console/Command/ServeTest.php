<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Console\Command;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use MagoAssistant\Mcp\Console\Command\Serve;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Mcp\Channel\ChannelFactory;
use MagoAssistant\Mcp\Service\Worker\HostCheck;
use MagoAssistant\Mcp\Service\Worker\ServerResolver;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use MagoAssistant\Mcp\Test\Unit\Fakes\RunsRelay;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ServeTest extends TestCase
{
    use RunsRelay;

    private const CRYPT_KEY = 'shop-crypt-key';

    protected function tearDown(): void
    {
        $this->stopRelay();
    }

    private function tester(string $address, bool $canStartProcesses = true): CommandTester
    {
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturn(self::CRYPT_KEY);
        $config = new Config(new FakeScopeConfig([Config::XML_PATH_WORKER_ADDRESS => $address]));
        $host = $this->createStub(HostCheck::class);
        $host->method('canStartProcesses')->willReturn($canStartProcesses);

        return new CommandTester(new Serve(
            $config,
            new ChannelFactory($config, $deployment, 'cli'),
            $this->createStub(ServerResolver::class),
            $host,
            new DirectoryList(sys_get_temp_dir()),
            $deployment
        ));
    }

    #[Test]
    public function statusSaysWhenNoWorkerRuns(): void
    {
        $tester = $this->tester('127.0.0.1:1');

        self::assertSame(1, $tester->execute(['--status' => true]));
        self::assertStringContainsString('No MCP worker answers at 127.0.0.1:1', $tester->getDisplay());
    }

    #[Test]
    public function statusDescribesARunningWorker(): void
    {
        $address = $this->startRelay([], self::CRYPT_KEY);
        $tester = $this->tester($address);

        self::assertSame(0, $tester->execute(['--status' => true]));
        self::assertStringContainsString('MCP worker at ' . $address, $tester->getDisplay());
        self::assertStringContainsString('root: ' . realpath($this->relayDir), $tester->getDisplay());
        self::assertMatchesRegularExpression('/pid: \d+/', $tester->getDisplay());
    }

    #[Test]
    public function stopEndsARunningWorker(): void
    {
        $address = $this->startRelay([], self::CRYPT_KEY);
        $tester = $this->tester($address);

        self::assertSame(0, $tester->execute(['--stop' => true]));
        $until = microtime(true) + 5;
        while (proc_get_status($this->relayProcess)['running'] && microtime(true) < $until) {
            usleep(50000);
        }
        self::assertFalse(proc_get_status($this->relayProcess)['running']);
    }

    #[Test]
    public function refusesToServeWhereProcessesCannotBeStarted(): void
    {
        $tester = $this->tester('127.0.0.1:0', false);

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('This host cannot run local MCP servers', $tester->getDisplay());
    }

    #[Test]
    public function saysSoWhenAWorkerAlreadyListens(): void
    {
        $address = $this->startRelay([], self::CRYPT_KEY);
        $tester = $this->tester($address);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('already running', $tester->getDisplay());
    }
}
