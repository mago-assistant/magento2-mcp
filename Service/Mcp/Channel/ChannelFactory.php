<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Mcp\Channel;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Worker\Handshake;
use MagoAssistant\Mcp\Service\Worker\WorkerClient;

/**
 * Only the command line starts processes. bin/magento, cron and the worker itself start a local server
 * directly; every other SAPI (a web request) reaches it through the MCP worker. One path per SAPI, also on
 * hosts where web PHP would be allowed to start processes, so both paths are the ones that get used.
 */
class ChannelFactory
{
    private const COMMAND_LINE_SAPIS = ['cli', 'phpdbg'];

    public function __construct(
        private readonly Config $config,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly ?string $sapi = null
    ) {
    }

    public function create(): ChannelInterface
    {
        // Read at run time: setup:di:compile runs on the command line and stores constructor defaults as
        // values, so a default of PHP_SAPI would be "cli" for every web request of a compiled install.
        if (in_array($this->sapi ?? PHP_SAPI, self::COMMAND_LINE_SAPIS, true)) {
            return new ProcessChannel();
        }

        return new WorkerChannel($this->workerClient());
    }

    public function workerClient(): WorkerClient
    {
        return new WorkerClient(
            $this->config->getWorkerAddress(),
            Handshake::key((string)$this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY))
        );
    }
}
