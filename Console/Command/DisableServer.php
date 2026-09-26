<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Console\Command;

use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
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
        $requested = (string)$input->getArgument('server');
        $row = $this->servers->getByName($requested);
        if ($row === null) {
            $output->writeln('<error>No such server: ' . OutputFormatter::escape($requested) . '</error>');

            return Command::FAILURE;
        }
        $name = (string)$row['name'];
        $this->servers->setEnabled($name, false);
        $this->catalog->refresh($name);
        $output->writeln(sprintf('<info>%s disabled.</info>', OutputFormatter::escape($name)));

        return Command::SUCCESS;
    }
}
