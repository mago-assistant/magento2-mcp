<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Cron;

use Magento\Framework\App\Filesystem\DirectoryList;
use MagoAssistant\Mcp\Cron\WorkerKeepalive;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Mcp\Channel\ChannelFactory;
use MagoAssistant\Mcp\Service\Worker\ServerResolver;
use MagoAssistant\Mcp\Service\Worker\Starter;
use MagoAssistant\Mcp\Service\Worker\WorkerClient;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class WorkerKeepaliveTest extends TestCase
{
    private WorkerClient&MockObject $client;
    private Starter&MockObject $starter;
    private ServerResolver&Stub $resolver;
    private string $root;

    protected function setUp(): void
    {
        $this->client = $this->createMock(WorkerClient::class);
        $this->starter = $this->createMock(Starter::class);
        $this->resolver = $this->createStub(ServerResolver::class);
        $this->root = (string)realpath(sys_get_temp_dir());
    }

    private function keepalive(bool $autostart = true): WorkerKeepalive
    {
        $channels = $this->createStub(ChannelFactory::class);
        $channels->method('workerClient')->willReturn($this->client);

        return new WorkerKeepalive(
            new Config(new FakeScopeConfig([Config::XML_PATH_WORKER_AUTOSTART => $autostart ? '1' : '0'])),
            $this->resolver,
            $channels,
            $this->starter,
            new DirectoryList($this->root)
        );
    }

    #[Test]
    public function doesNothingWhenAutostartIsOff(): void
    {
        $this->client->expects(self::never())->method('status');
        $this->starter->expects(self::never())->method('start');

        $this->keepalive(false)->execute();
    }

    #[Test]
    public function startsNothingAndStopsAWorkerWhenNoLocalServerIsEnabled(): void
    {
        $this->resolver->method('anyEnabled')->willReturn(false);
        $this->client->method('status')->willReturn(['ok' => true, 'root' => $this->root]);
        $this->client->expects(self::once())->method('stop');
        $this->starter->expects(self::never())->method('start');

        $this->keepalive()->execute();
    }

    #[Test]
    public function leavesThingsAloneWhenNothingIsEnabledAndNothingRuns(): void
    {
        $this->resolver->method('anyEnabled')->willReturn(false);
        $this->client->method('status')->willReturn(null);
        $this->client->expects(self::never())->method('stop');
        $this->starter->expects(self::never())->method('start');

        $this->keepalive()->execute();
    }

    #[Test]
    public function startsTheWorkerWhenALocalServerIsEnabledAndNothingAnswers(): void
    {
        $this->resolver->method('anyEnabled')->willReturn(true);
        $this->client->method('status')->willReturn(null);
        $this->client->expects(self::never())->method('stop');
        $this->starter->expects(self::once())->method('start');

        $this->keepalive()->execute();
    }

    #[Test]
    public function replacesAWorkerOfAnotherReleaseOnTheNextRun(): void
    {
        $this->resolver->method('anyEnabled')->willReturn(true);
        $this->client->method('status')->willReturn(['ok' => true, 'root' => '/var/www/releases/old']);
        $this->client->expects(self::once())->method('stop');
        $this->starter->expects(self::never())->method('start');

        $this->keepalive()->execute();
    }

    #[Test]
    public function leavesTheWorkerOfThisReleaseRunning(): void
    {
        $this->resolver->method('anyEnabled')->willReturn(true);
        $this->client->method('status')->willReturn(['ok' => true, 'root' => $this->root]);
        $this->client->expects(self::never())->method('stop');
        $this->starter->expects(self::never())->method('start');

        $this->keepalive()->execute();
    }
}
