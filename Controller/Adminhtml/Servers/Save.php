<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Controller\Adminhtml\Servers;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mcp::manage';

    public function __construct(
        Context $context,
        private readonly ServerRepositoryInterface $servers,
        private readonly ToolCatalog $catalog,
        private readonly ModeClassifier $classifier
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $request = $this->getRequest();
        $name = DiscoveredServer::normaliseName((string)$request->getParam('name', ''));
        $redirect = $this->resultRedirectFactory->create();
        if ($name === '') {
            $this->messageManager->addErrorMessage(__('A server name is required.'));

            return $redirect->setPath('mago_mcp/servers/index');
        }
        $existing = $this->servers->getByName($name);
        // Merge: tools not on this form (a server that failed to list, a tool the server dropped for
        // now) keep their stored override; an empty select clears one.
        $overrides = $existing['tool_overrides'] ?? [];
        foreach ((array)$request->getParam('modes', []) as $tool => $mode) {
            $mode = (string)$mode;
            if ($mode === '') {
                unset($overrides[(string)$tool]);
            } elseif ($this->classifier->isValidMode($mode)) {
                $overrides[(string)$tool] = $mode;
            }
        }
        $row = [
            'name' => $name,
            'enabled' => (bool)$request->getParam('enabled', false),
            'tool_overrides' => $overrides,
            'source' => $existing['source'] ?? DiscoveredServer::SOURCE_MANUAL,
            'command' => $existing['command'] ?? [],
            'env' => $existing['env'] ?? [],
            'cwd' => $existing['cwd'] ?? null,
            'missing' => $existing['missing'] ?? false,
            'last_error' => $existing['last_error'] ?? null,
        ];
        if ($row['source'] === DiscoveredServer::SOURCE_MANUAL) {
            $row['command'] = preg_split('/\s+/', trim((string)$request->getParam('command', '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $row['cwd'] = trim((string)$request->getParam('cwd', '')) ?: null;
            $row['env'] = [];
            foreach (preg_split('/\R/', (string)$request->getParam('env', '')) ?: [] as $line) {
                [$key, $value] = array_pad(explode('=', trim($line), 2), 2, '');
                if ($key !== '') {
                    $row['env'][$key] = $value;
                }
            }
            if ($row['command'] === []) {
                $this->messageManager->addErrorMessage(__('A command is required for a manual server.'));

                return $redirect->setPath('mago_mcp/servers/edit', ['name' => $name]);
            }
        }
        $this->servers->save($row);
        $this->catalog->refresh($name);
        $this->messageManager->addSuccessMessage(__('MCP server "%1" saved.', $name));

        return $redirect->setPath('mago_mcp/servers/edit', ['name' => $name]);
    }
}
