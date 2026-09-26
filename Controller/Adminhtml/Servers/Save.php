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
use MagoAssistant\Mcp\Block\Adminhtml\ServerForm;
use MagoAssistant\Mcp\Service\Admin\ServerAdminService;

/**
 * Saves the form; on refusal the input goes back to the form (without the token) with every reason shown.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mcp::manage';

    public function __construct(Context $context, private readonly ServerAdminService $admin)
    {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $post = (array)$this->getRequest()->getPostValue();
        $existing = trim((string)($post['existing_name'] ?? ''));
        $redirect = $this->resultRedirectFactory->create();
        try {
            $name = $this->admin->save($post, $existing === '' ? null : $existing);
            $this->messageManager->addSuccessMessage(__('MCP server "%1" saved.', $name));

            return $redirect->setPath('mago_mcp/servers/index');
        } catch (\InvalidArgumentException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            unset($post['bearer_token'], $post['form_key']);
            $this->_session->setData(ServerForm::FORM_DATA_KEY, $post);

            return $existing === ''
                ? $redirect->setPath('mago_mcp/servers/new')
                : $redirect->setPath('mago_mcp/servers/edit', ['name' => $existing]);
        }
    }
}
