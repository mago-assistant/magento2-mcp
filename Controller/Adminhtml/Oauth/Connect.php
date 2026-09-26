<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Controller\Adminhtml\Oauth;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use MagoAssistant\Mcp\Service\OAuth\ConnectionService;
use MagoAssistant\Mcp\Service\OAuth\OAuthException;

/**
 * Sends the current admin user to the server's authorization page (GET, protected by the admin secret key).
 * Manage-only in 1.0: the link lives on the servers grid.
 */
class Connect extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mcp::manage';

    public function __construct(
        Context $context,
        private readonly ConnectionService $connectionService
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        try {
            $server = $this->connectionService->getServer((string)$this->getRequest()->getParam('name'));
            $url = $this->connectionService->start($server, (int)$this->_auth->getUser()->getId());
            return $redirect->setUrl($url);
        } catch (OAuthException $e) {
            $this->messageManager->addErrorMessage(__('Could not connect: %1', $e->getMessage()));
            return $redirect->setPath('mago_mcp/servers/index');
        }
    }
}
