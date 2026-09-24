<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Console\Command;

use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DisableServer extends Command
{
    public function __construct(
        private readonly ServerRepositoryInterface $servers,
        private readonly ToolCatalog $catalog,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('mago:mcp:disable')
            ->setDescription('Disable an MCP server for the assistant.')
            ->addArgument('server', InputArgument::REQUIRED, 'Server name');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string)$input->getArgument('server');
        if ($this->servers->getByName($name) === null) {
            $output->writeln('<error>No such server: ' . $name . '</error>');

            return Command::FAILURE;
        }
        $this->servers->setEnabled($name, false);
        $this->catalog->refresh($name);
        $output->writeln(sprintf('<info>%s disabled.</info>', $name));

        return Command::SUCCESS;
    }
}
