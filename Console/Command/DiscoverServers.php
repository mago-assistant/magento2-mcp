<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Service\Discovery\ServerScanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DiscoverServers extends Command
{
    public function __construct(
        private readonly ServerScanner $scanner,
        private readonly ServerRepositoryInterface $servers,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('mago:mcp:discover')
            ->setDescription('Scan Composer packages and .mcp.json for MCP servers (new ones are added disabled).');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable $e) {
            // area may already be set
        }
        $result = $this->servers->merge($this->scanner->scan());
        $output->writeln(sprintf(
            '<info>Inserted %d, updated %d, flagged %d missing.</info>',
            $result['inserted'],
            $result['updated'],
            $result['missing']
        ));
        foreach ($this->servers->getAll() as $row) {
            $output->writeln(sprintf(
                '  %-20s %-9s %s%s',
                OutputFormatter::escape((string)$row['name']),
                OutputFormatter::escape((string)$row['source']),
                $row['enabled'] ? 'enabled ' : 'disabled',
                $row['missing'] ? ' (missing)' : ''
            ));
        }

        return Command::SUCCESS;
    }
}
