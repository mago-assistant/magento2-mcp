<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Worker;

use Magento\Framework\App\Filesystem\DirectoryList;
use MagoAssistant\Mcp\Service\Worker\WorkerProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkerProcessTest extends TestCase
{
    private string $root = '';
    /** @var resource|null */
    private $process = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/worker-process-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var', 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
        }
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * @param string[] $arguments
     */
    private function spawn(array $arguments): int
    {
        $pipes = [];
        $this->process = proc_open([PHP_BINARY, '-r', 'sleep(30);', ...$arguments], [], $pipes);

        return (int)proc_get_status($this->process)['pid'];
    }

    private function workerProcess(): WorkerProcess
    {
        return new WorkerProcess(new DirectoryList($this->root));
    }

    #[Test]
    public function endsTheRecordedWorker(): void
    {
        $pid = $this->spawn(['mago:mcp:serve']);
        $this->workerProcess()->record($pid);

        self::assertTrue($this->workerProcess()->kill());
        $until = microtime(true) + 3;
        while (proc_get_status($this->process)['running'] && microtime(true) < $until) {
            usleep(50000);
        }
        self::assertFalse(proc_get_status($this->process)['running']);
    }

    #[Test]
    public function leavesAnotherProcessThatReusedThePidAlone(): void
    {
        $pid = $this->spawn(['something-else']);
        $this->workerProcess()->record($pid);

        self::assertFalse($this->workerProcess()->kill());
        self::assertTrue(proc_get_status($this->process)['running']);
    }

    #[Test]
    public function doesNothingWithoutARecord(): void
    {
        self::assertFalse($this->workerProcess()->kill());
    }

    #[Test]
    public function clearRemovesOnlyItsOwnRecord(): void
    {
        $this->workerProcess()->record(12345);
        $this->workerProcess()->clear(999);
        self::assertFileExists($this->root . '/var/mago-mcp-worker.pid');

        $this->workerProcess()->clear(12345);
        self::assertFileDoesNotExist($this->root . '/var/mago-mcp-worker.pid');
    }
}
