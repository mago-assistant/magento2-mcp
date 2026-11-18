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
 * Redirect target of the authorization server. It carries no admin secret key, so CSRF protection
 * comes from the one-time state bound to this admin session.
 */
class Callback extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mcp::manage';

    /** @var string[] */
    protected $_publicActions = ['callback'];

    public function __construct(
        Context $context,
        private readonly ConnectionService $connectionService
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $request = $this->getRequest();
        $error = (string)$request->getParam('error');
        try {
            if ($error !== '') {
                throw new OAuthException(trim($error . ' ' . $request->getParam('error_description')));
            }
            $server = $this->connectionService->complete(
                (string)$request->getParam('state'),
                (string)$request->getParam('code'),
                (int)$this->_auth->getUser()->getId()
            );
            $this->messageManager->addSuccessMessage(__('Connected to %1.', $server->label()));
        } catch (OAuthException $e) {
            $this->messageManager->addErrorMessage(__('Could not connect: %1', $e->getMessage()));
        }

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $redirect->setPath('mago_mcp/servers/index');
    }
}
