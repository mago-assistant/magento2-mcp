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
use MagoAssistant\Mcp\Service\Admin\ServerAdminService;

/**
 * Deletes a manual server with every admin's tokens and its client registration.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mcp::manage';

    public function __construct(Context $context, private readonly ServerAdminService $admin)
    {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $name = (string)$this->getRequest()->getParam('name', '');
        try {
            $this->admin->delete($name);
            $this->messageManager->addSuccessMessage(__('MCP server "%1" deleted.', $name));
        } catch (\InvalidArgumentException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->resultRedirectFactory->create()->setPath('mago_mcp/servers/index');
    }
}
