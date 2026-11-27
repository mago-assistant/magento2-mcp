<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Cron;

use Magento\Framework\App\Filesystem\DirectoryList;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Mcp\Channel\ChannelFactory;
use MagoAssistant\Mcp\Service\Worker\ServerResolver;
use MagoAssistant\Mcp\Service\Worker\Starter;

/**
 * Every minute: keep one MCP worker of this release running while a local server is enabled, none
 * otherwise. A worker of another release (after a deploy) is told to stop; the next run starts this one.
 */
class WorkerKeepalive
{
    public function __construct(
        private readonly Config $config,
        private readonly ServerResolver $resolver,
        private readonly ChannelFactory $channels,
        private readonly Starter $starter,
        private readonly DirectoryList $directoryList
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isWorkerAutostart()) {
            return;
        }
        $client = $this->channels->workerClient();
        $status = $client->status();
        if (!$this->resolver->anyEnabled()) {
            if ($status !== null) {
                $client->stop();
            }

            return;
        }
        if ($status === null) {
            $this->starter->start();

            return;
        }
        $root = realpath($this->directoryList->getRoot()) ?: $this->directoryList->getRoot();
        if (($status['root'] ?? null) !== $root) {
            $client->stop();
        }
    }
}
