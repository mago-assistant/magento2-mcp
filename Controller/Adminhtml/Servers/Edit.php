<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Controller\Adminhtml\Servers;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use MagoAssistant\Mcp\Api\ServerRepositoryInterface;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mcp::manage';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly ServerRepositoryInterface $servers
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $name = (string)$this->getRequest()->getParam('name', '');
        if ($name !== '' && $this->servers->getByName($name) === null) {
            $this->messageManager->addErrorMessage(__('No MCP server named "%1".', $name));

            return $this->resultRedirectFactory->create()->setPath('mago_mcp/servers/index');
        }
        $page = $this->pageFactory->create();
        $page->setActiveMenu('MagoAssistant_Mcp::servers');
        $page->getConfig()->getTitle()->prepend((string)($name === '' ? __('New MCP Server') : __('Edit MCP Server')));

        return $page;
    }
}
