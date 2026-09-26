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
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;

class Toggle extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mcp::manage';

    public function __construct(
        Context $context,
        private readonly ServerRepositoryInterface $servers,
        private readonly ToolCatalog $catalog
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $name = (string)$this->getRequest()->getParam('name', '');
        $redirect = $this->resultRedirectFactory->create()->setPath('mago_mcp/servers/index');
        $row = $name === '' ? null : $this->servers->getByName($name);
        if ($row === null) {
            $this->messageManager->addErrorMessage(__('No MCP server named "%1".', $name));

            return $redirect;
        }
        $enabled = !$row['enabled'];
        $this->servers->setEnabled($name, $enabled);
        $this->catalog->refresh($name);
        $this->messageManager->addSuccessMessage(
            __('MCP server "%1" %2.', $name, $enabled ? 'enabled' : 'disabled')
        );

        return $redirect;
    }
}
