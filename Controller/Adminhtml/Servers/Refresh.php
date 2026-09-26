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
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;

class Refresh extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mcp::manage';

    public function __construct(Context $context, private readonly ToolCatalog $catalog)
    {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $name = (string)$this->getRequest()->getParam('name', '');
        $this->catalog->refresh($name === '' ? null : $name);
        $this->messageManager->addSuccessMessage(__('Tool list cache cleared.'));

        return $this->resultRedirectFactory->create()->setPath('mago_mcp/servers/index');
    }
}
