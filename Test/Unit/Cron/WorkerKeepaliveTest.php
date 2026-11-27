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
use MagoAssistant\Mcp\Service\Worker\BuildIdentity;
use MagoAssistant\Mcp\Service\Worker\HostCheck;
use MagoAssistant\Mcp\Service\Worker\ServerResolver;
use MagoAssistant\Mcp\Service\Worker\Starter;
use MagoAssistant\Mcp\Service\Worker\WorkerClient;
use MagoAssistant\Mcp\Service\Worker\WorkerProcess;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Each test states the calls it is about; the other collaborators are mocks only so one class serves all.
 */
#[AllowMockObjectsWithoutExpectations]
final class WorkerKeepaliveTest extends TestCase
{
    private WorkerClient&MockObject $client;
    private Starter&MockObject $starter;
    private WorkerProcess&MockObject $process;
    private ServerResolver&Stub $resolver;
    private string $root;

    protected function setUp(): void
    {
        $this->client = $this->createMock(WorkerClient::class);
        $this->starter = $this->createMock(Starter::class);
        $this->process = $this->createMock(WorkerProcess::class);
        $this->resolver = $this->createStub(ServerResolver::class);
        $this->root = (string)realpath(sys_get_temp_dir());
    }

    private function keepalive(bool $autostart = true, bool $addonEnabled = true, bool $canStartProcesses = true): WorkerKeepalive
    {
        $channels = $this->createStub(ChannelFactory::class);
        $channels->method('workerClient')->willReturn($this->client);
        $host = $this->createStub(HostCheck::class);
        $host->method('canStartProcesses')->willReturn($canStartProcesses);

        return new WorkerKeepalive(
            new Config(new FakeScopeConfig([
                Config::XML_PATH_WORKER_AUTOSTART => $autostart ? '1' : '0',
                Config::XML_PATH_ENABLED => $addonEnabled ? '1' : '0',
            ])),
            $this->resolver,
            $channels,
            $this->starter,
            new DirectoryList($this->root),
            $host,
            $this->process
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function running(?string $root = null, ?string $build = null): array
    {
        return ['state' => 'running', 'root' => $root ?? $this->root, 'build' => $build ?? BuildIdentity::of($this->root), 'pid' => 1];
    }

    private function expectNothingDone(): void
    {
        $this->client->expects(self::never())->method('stop');
        $this->starter->expects(self::never())->method('start');
        $this->process->expects(self::never())->method('kill');
    }

    #[Test]
    public function doesNothingWhenAutostartIsOff(): void
    {
        $this->client->expects(self::never())->method('status');
        $this->expectNothingDone();

        $this->keepalive(autostart: false)->execute();
    }

    #[Test]
    public function doesNothingWhereProcessesCannotBeStarted(): void
    {
        $this->resolver->method('anyEnabled')->willReturn(true);
        $this->client->expects(self::never())->method('status');
        $this->expectNothingDone();

        $this->keepalive(canStartProcesses: false)->execute();
    }

    #[Test]
    public function stopsTheWorkerWhenTheAddonIsSwitchedOff(): void
    {
        $this->resolver->method('anyEnabled')->willReturn(true);
        $this->client->method('status')->willReturn($this->running());
        $this->client->expects(self::once())->method('stop')->willReturn(true);
        $this->starter->expects(self::never())->method('start');

        $this->keepalive(addonEnabled: false)->execute();
    }

    #[Test]
    public function stopsTheWorkerWhenNoLocalServerIsEnabled(): void
    {
        $this->resolver->method('anyEnabled')->willReturn(false);
        $this->client->method('status')->willReturn($this->running());
        $this->client->expects(self::once())->method('stop')->willReturn(true);
        $this->starter->expects(self::never())->method('start');

        $this->keepalive()->execute();
    }

    #[Test]
    public function leavesThingsAloneWhenNothingIsEnabledAndNothingRuns(): void
    {
        $this->resolver->method('anyEnabled')->willReturn(false);
        $this->client->method('status')->willReturn(null);
        $this->expectNothingDone();

        $this->keepalive()->execute();
    }

    #[Test]
    public function startsTheWorkerWhenALocalServerIsEnabledAndNothingAnswers(): void
    {
        $this->resolver->method('anyEnabled')->willReturn(true);
        $this->client->method('status')->willReturn(null);
        $this->process->method('kill')->willReturn(false);
        $this->client->expects(self::never())->method('stop');
        $this->starter->expects(self::once())->method('start');

        $this->keepalive()->execute();
    }

    #[Test]
    public function aWorkerThatNoLongerListensWhereTheSettingsSayIsEnded(): void
    {
        // The worker address or listen setting changed while it ran: nothing answers at the configured
        // address and a new worker cannot bind the port the old one holds.
        $this->resolver->method('anyEnabled')->willReturn(true);
        $this->client->method('status')->willReturn(null);
        $this->process->expects(self::once())->method('kill')->willReturn(true);
        $this->starter->expects(self::never())->method('start');

        $this->keepalive()->execute();
    }

    #[Test]
    public function aBusyWorkerIsUp(): void
    {
        $this->resolver->method('anyEnabled')->willReturn(true);
        $this->client->method('status')->willReturn(['state' => 'busy']);
        $this->expectNothingDone();

        $this->keepalive()->execute();
    }

    #[Test]
    public function aWorkerThatCannotBeTalkedToIsEndedThroughItsPidFile(): void
    {
        $this->resolver->method('anyEnabled')->willReturn(true);
        $this->client->method('status')->willReturn(['state' => 'refused']);
        $this->process->expects(self::once())->method('kill')->willReturn(true);
        $this->starter->expects(self::never())->method('start');

        $this->keepalive()->execute();
    }

    #[Test]
    public function replacesAWorkerOfAnotherReleaseInTheSameRun(): void
    {
        $this->resolver->method('anyEnabled')->willReturn(true);
        $this->client->method('status')->willReturn($this->running(root: '/var/www/releases/old'));
        $this->client->expects(self::once())->method('stop')->willReturn(true);
        $this->starter->expects(self::once())->method('start');

        $this->keepalive()->execute();
    }

    #[Test]
    public function replacesAWorkerAfterAnInPlaceDeploy(): void
    {
        $this->resolver->method('anyEnabled')->willReturn(true);
        $this->client->method('status')->willReturn($this->running(build: 'an-older-build'));
        $this->client->expects(self::once())->method('stop')->willReturn(true);
        $this->starter->expects(self::once())->method('start');

        $this->keepalive()->execute();
    }

    #[Test]
    public function leavesTheWorkerOfThisBuildRunning(): void
    {
        $this->resolver->method('anyEnabled')->willReturn(true);
        $this->client->method('status')->willReturn($this->running());
        $this->expectNothingDone();

        $this->keepalive()->execute();
    }
}
