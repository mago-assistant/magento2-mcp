<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Setup;

use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Model\Cache\Type\McpTools;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Discovery\ServerScanner;

/**
 * Runs after every setup:upgrade: enables the module's cache type (Magento ships new types disabled),
 * renames rows an earlier version stored under a differently normalised name, and rescans so newly
 * installed MCP packages show up in the grid, disabled.
 */
class RecurringData implements InstallDataInterface
{
    public function __construct(
        private readonly ServerScanner $scanner,
        private readonly ServerRepositoryInterface $servers,
        private readonly StateInterface $cacheState,
        private readonly ErrorLogger $errorLogger,
        private readonly ToolCatalog $catalog
    ) {
    }

    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context): void
    {
        try {
            if (!$this->cacheState->isEnabled(McpTools::TYPE_IDENTIFIER)) {
                $this->cacheState->setEnabled(McpTools::TYPE_IDENTIFIER, true);
                $this->cacheState->persist();
            }
        } catch (\Throwable $e) {
            $this->errorLogger->addLog('MCP cache type enable during setup:upgrade', $e->getMessage());
        }
        try {
            $this->servers->migrateLegacyNames();
        } catch (\Throwable $e) {
            $this->errorLogger->addLog('MCP server name migration during setup:upgrade', $e->getMessage());
        }
        try {
            $result = $this->servers->merge($this->scanner->scan());
            foreach ($result['names'] as $name) {
                $this->catalog->refresh($name);
            }
        } catch (\Throwable $e) {
            $this->errorLogger->addLog('MCP discovery during setup:upgrade', $e->getMessage());
        }
    }
}
