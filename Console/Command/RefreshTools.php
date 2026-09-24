<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Console\Command;

use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RefreshTools extends Command
{
    public function __construct(private readonly ToolCatalog $catalog, ?string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('mago:mcp:refresh')
            ->setDescription('Drop the cached tool list of one server, or of all servers.')
            ->addArgument('server', InputArgument::OPTIONAL, 'Server name');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('server');
        $this->catalog->refresh($name === null ? null : (string)$name);
        $output->writeln('<info>Tool list cache cleared.</info>');

        return Command::SUCCESS;
    }
}
