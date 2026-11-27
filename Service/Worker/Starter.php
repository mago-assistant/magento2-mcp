<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Worker;

use Magento\Framework\App\Filesystem\DirectoryList;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\PhpExecutableFinder;

// phpcs:disable Magento2.Security.InsecureFunction, Magento2.Functions.DiscouragedFunction
// Runs from cron, on the command line. sh puts the worker in the background with nohup and exits at once,
// so cron does not wait; the worker's output goes to var/log/mago-mcp-worker.log.

/**
 * Start bin/magento mago:mcp:serve in the background.
 */
class Starter
{
    private const SCRIPT = 'nohup "$0" "$@" >> "$MAGO_MCP_WORKER_LOG" 2>&1 < /dev/null &';

    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly PhpExecutableFinder $phpFinder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function start(): void
    {
        $root = $this->directoryList->getRoot();
        $php = $this->phpFinder->find(false) ?: 'php';
        $pipes = [];
        $process = proc_open(
            ['/bin/sh', '-c', self::SCRIPT, $php, $root . '/bin/magento', 'mago:mcp:serve'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']]
                + InheritedDescriptors::shadow(),
            $pipes,
            $root,
            array_merge(getenv(), ['MAGO_MCP_WORKER_LOG' => $this->directoryList->getPath(DirectoryList::LOG) . '/mago-mcp-worker.log'])
        );
        if (!is_resource($process)) {
            $this->logger->error('Mago MCP: could not start the MCP worker.');

            return;
        }
        proc_close($process);
        $this->logger->info('Mago MCP: started the MCP worker.');
    }
}
