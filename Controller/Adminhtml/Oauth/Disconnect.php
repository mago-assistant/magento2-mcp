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
 * Removes the current admin user's own token (GET, protected by the admin secret key)
 */
class Disconnect extends Action implements HttpGetActionInterface
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
        try {
            $server = $this->connectionService->getServer((string)$this->getRequest()->getParam('name'));
            $this->connectionService->disconnect($server, (int)$this->_auth->getUser()->getId());
            $this->messageManager->addSuccessMessage(__('Disconnected from %1.', $server->label()));
        } catch (OAuthException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $redirect->setPath('mago_mcp/servers/index');
    }
}
