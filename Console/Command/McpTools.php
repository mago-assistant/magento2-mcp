<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Console\Command;

use MagoAssistant\Mcp\Service\McpTool;
use MagoAssistant\Mcp\Service\ToolProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class McpTools extends Command
{
    public function __construct(
        private readonly ToolProvider $toolProvider,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('mago:mcp:tools')
            ->setDescription('List the tools the Mago assistant gets from the configured MCP servers.')
            ->addOption('refresh', 'r', InputOption::VALUE_NONE, 'Fetch the tool lists instead of using the cache.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $servers = $this->toolProvider->getServers();
        if ($servers === []) {
            $output->writeln('<comment>No MCP servers are registered.</comment>');
            return Command::SUCCESS;
        }

        $exitCode = Command::SUCCESS;
        foreach ($servers as $server) {
            $output->writeln(
                sprintf('<info>%s</info> (%s) %s', $server->getLabel(), $server->getCode(), $server->getUrl())
            );
            if (!$server->isEnabled()) {
                $output->writeln('  <comment>Disabled.</comment>');
                continue;
            }

            $definition = $this->toolProvider->getDefinition($server, (bool)$input->getOption('refresh'));
            if (isset($definition['error'])) {
                $output->writeln('  <error>' . $definition['error'] . '</error>');
                $exitCode = Command::FAILURE;
                continue;
            }

            $table = new Table($output);
            $table->setHeaders(['Remote tool', 'Assistant tool', 'Read-only']);
            foreach ($definition['tools'] as $tool) {
                $isReadOnly = ($tool['annotations']['readOnlyHint'] ?? false) === true;
                $table->addRow([
                    $tool['name'],
                    McpTool::nameFor($server->getCode(), (string)$tool['name']),
                    $isReadOnly ? 'yes' : 'no',
                ]);
            }
            $table->render();
        }

        return $exitCode;
    }
}
