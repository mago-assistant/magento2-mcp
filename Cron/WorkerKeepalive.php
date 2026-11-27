<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Cron;

use Magento\Framework\App\Filesystem\DirectoryList;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Mcp\Channel\ChannelFactory;
use MagoAssistant\Mcp\Service\Worker\BuildIdentity;
use MagoAssistant\Mcp\Service\Worker\HostCheck;
use MagoAssistant\Mcp\Service\Worker\ServerResolver;
use MagoAssistant\Mcp\Service\Worker\Starter;
use MagoAssistant\Mcp\Service\Worker\WorkerProcess;

/**
 * Every minute: keep one MCP worker of this build running while the addon is on and a local server is
 * enabled, none otherwise. A worker of another release or build is replaced in the same run; one that
 * cannot prove itself any more (crypt key changed, older protocol) is ended through its pid file and the
 * next run starts the current one.
 */
class WorkerKeepalive
{
    public function __construct(
        private readonly Config $config,
        private readonly ServerResolver $resolver,
        private readonly ChannelFactory $channels,
        private readonly Starter $starter,
        private readonly DirectoryList $directoryList,
        private readonly HostCheck $host,
        private readonly WorkerProcess $process
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isWorkerAutostart() || !$this->host->canStartProcesses()) {
            return;
        }
        $client = $this->channels->workerClient();
        $status = $client->status();
        $state = $status['state'] ?? null;
        if (!$this->config->isEnabled() || !$this->resolver->anyEnabled()) {
            if ($state === 'running') {
                $client->stop();
            } elseif ($state === 'refused') {
                $this->process->kill();
            }

            return;
        }
        if ($status === null) {
            $this->starter->start();

            return;
        }
        if ($state === 'busy') {
            return;
        }
        if ($state === 'refused') {
            $this->process->kill();

            return;
        }
        $root = realpath($this->directoryList->getRoot()) ?: $this->directoryList->getRoot();
        if (($status['root'] ?? null) !== $root || ($status['build'] ?? null) !== BuildIdentity::of($root)) {
            // A stopping worker closes its port at once, so the new one can start in this run.
            if ($client->stop()) {
                $this->starter->start();
            }
        }
    }
}
