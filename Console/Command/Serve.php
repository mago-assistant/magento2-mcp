<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Console\Command;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Config\ConfigOptionsListConstants;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Mcp\Channel\ChannelFactory;
use MagoAssistant\Mcp\Service\Worker\Handshake;
use MagoAssistant\Mcp\Service\Worker\HostCheck;
use MagoAssistant\Mcp\Service\Worker\Relay;
use MagoAssistant\Mcp\Service\Worker\BuildIdentity;
use MagoAssistant\Mcp\Service\Worker\ServerResolver;
use MagoAssistant\Mcp\Service\Worker\WorkerProcess;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The MCP worker: starts local MCP servers for the admin, which never starts processes itself.
 */
class Serve extends Command
{
    public function __construct(
        private readonly Config $config,
        private readonly ChannelFactory $channels,
        private readonly ServerResolver $resolver,
        private readonly HostCheck $host,
        private readonly DirectoryList $directoryList,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly WorkerProcess $process,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('mago:mcp:serve')
            ->setDescription('Run the MCP worker, which starts local MCP servers for the admin.')
            ->addOption('listen', null, InputOption::VALUE_REQUIRED, 'host:port to bind (default: the "MCP worker listens on" setting)')
            ->addOption('max-connections', null, InputOption::VALUE_REQUIRED, 'Connections served at once', '8')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Report the running worker and exit')
            ->addOption('stop', null, InputOption::VALUE_NONE, 'Stop the running worker once its open calls end');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->channels->workerClient();
        if ($input->getOption('status')) {
            $status = $client->status();
            if ($status === null) {
                $output->writeln(sprintf('No MCP worker answers at %s.', $client->address()));

                return Command::FAILURE;
            }
            if ($status['state'] !== 'running') {
                $output->writeln(sprintf(
                    $status['state'] === 'busy'
                        ? 'The MCP worker at %s is busy.'
                        : 'Something answers at %s but cannot prove it is this shop\'s MCP worker (another crypt key, '
                        . 'an older worker or another program). Cron ends a worker of this shop through its pid file.',
                    $client->address()
                ));

                return $status['state'] === 'busy' ? Command::SUCCESS : Command::FAILURE;
            }
            $output->writeln(sprintf(
                'MCP worker at %s, root: %s, build: %s, pid: %d, open connections: %d, running since %s.',
                $client->address(),
                (string)$status['root'],
                (string)$status['build'],
                (int)$status['pid'],
                (int)$status['connections'],
                gmdate('Y-m-d H:i:s', (int)$status['started']) . ' UTC'
            ));

            return Command::SUCCESS;
        }
        if ($input->getOption('stop')) {
            if (!$client->stop()) {
                $output->writeln(sprintf('No MCP worker answers at %s.', $client->address()));

                return Command::FAILURE;
            }
            $output->writeln('The MCP worker stops once its open calls end.');

            return Command::SUCCESS;
        }
        if (!$this->host->canStartProcesses()) {
            $output->writeln(
                '<error>This host cannot run local MCP servers: proc_open is disabled for command-line PHP too. '
                . 'Remote MCP servers are not affected.</error>'
            );

            return Command::FAILURE;
        }

        if ($this->keys() === []) {
            $output->writeln('<error>This install has no crypt key in app/etc/env.php; the MCP worker cannot authenticate its clients.</error>');

            return Command::FAILURE;
        }

        $listen = (string)($input->getOption('listen') ?: $this->config->getWorkerListen());
        $root = $this->directoryList->getRoot();
        $relay = new Relay(
            \Closure::fromCallable($this->resolver),
            fn (): array => $this->keys(true),
            $root,
            max(1, (int)$input->getOption('max-connections')),
            static function (string $message) use ($output): void {
                $output->writeln(gmdate('Y-m-d H:i:s') . ' ' . $message);
            },
            5.0,
            BuildIdentity::of($root)
        );
        try {
            $bound = $relay->listen($listen);
        } catch (\RuntimeException $e) {
            $status = $client->status();
            if ($status !== null) {
                $output->writeln(sprintf('An MCP worker is already running at %s (root: %s).', $client->address(), (string)$status['root']));

                return Command::SUCCESS;
            }
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        if (function_exists('posix_setsid')) {
            posix_setsid();
        }
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, static function () use ($relay): void {
                    $relay->stopNow();
                });
            }
        }
        $pid = (int)getmypid();
        $this->process->record($pid);
        $output->writeln(sprintf('%s MCP worker listening on %s (root: %s, pid %d).', gmdate('Y-m-d H:i:s'), $bound, $root, $pid));
        try {
            $relay->run();
        } finally {
            $this->process->clear($pid);
        }
        $output->writeln(gmdate('Y-m-d H:i:s') . ' MCP worker stopped.');

        return Command::SUCCESS;
    }

    /**
     * The worker keys of this install's crypt key; read again on every request so a changed key applies
     * without a restart.
     *
     * @return string[]
     */
    private function keys(bool $fresh = false): array
    {
        if ($fresh) {
            $this->deploymentConfig->resetData();
        }

        return Handshake::keys((string)$this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY));
    }
}
