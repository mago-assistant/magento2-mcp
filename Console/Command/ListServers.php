<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListServers extends Command
{
    public function __construct(
        private readonly ServerRepositoryInterface $servers,
        private readonly ToolCatalog $catalog,
        private readonly State $appState,
        private readonly ModeClassifier $classifier,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('mago:mcp:list')
            ->setDescription('List MCP servers (source, transport, auth, state), or the tools of one server with their type.')
            ->addArgument('server', InputArgument::OPTIONAL, 'Server name to show tools for');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable $e) {
            // area may already be set
        }
        $name = $input->getArgument('server');
        if ($name === null) {
            foreach ($this->servers->getAll() as $row) {
                // Values from the database or a server's stderr are escaped so a stray "<" cannot
                // open or close console formatting tags.
                $output->writeln(sprintf(
                    '%-20s %-9s %-6s %-6s %-8s %s',
                    OutputFormatter::escape((string)$row['name']),
                    OutputFormatter::escape((string)$row['source']),
                    OutputFormatter::escape((string)$row['transport']),
                    OutputFormatter::escape((string)$row['auth_type']),
                    $row['enabled'] ? 'enabled' : 'disabled',
                    OutputFormatter::escape(
                        $row['transport'] === 'http' ? (string)$row['url'] : implode(' ', $row['command'])
                    )
                ));
                if ($row['last_error']) {
                    $output->writeln('    <error>' . OutputFormatter::escape((string)$row['last_error']) . '</error>');
                }
            }

            return Command::SUCCESS;
        }
        $row = $this->servers->getByName((string)$name);
        if ($row === null) {
            $output->writeln('<error>No such server: ' . OutputFormatter::escape((string)$name) . '</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf(
            '<info>%s</info> — %s%s',
            OutputFormatter::escape($row['label'] !== '' ? (string)$row['label'] : (string)$row['name']),
            OutputFormatter::escape($row['transport'] === 'http' ? (string)$row['url'] : implode(' ', $row['command'])),
            $row['read_only'] ? ' (read-only server)' : ''
        ));
        foreach ($this->catalog->entriesForServer((string)$name) as $entry) {
            $output->writeln(sprintf(
                '%-36s %-6s (%s)%s%s%s',
                OutputFormatter::escape($entry->tool),
                $entry->mode,
                $entry->modeOrigin,
                $entry->irreversible ? ' irreversible' : '',
                $entry->personalData ? ' personal-data' : '',
                $this->classifier->isExecutionSurface($entry->tool) ? ' execution' : ''
            ));
        }

        return Command::SUCCESS;
    }
}
