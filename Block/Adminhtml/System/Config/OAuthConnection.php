<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use MagoAssistant\Mcp\Service\OAuth\ConnectionService;
use MagoAssistant\Mcp\Service\OAuth\CurrentAdminUser;
use MagoAssistant\Mcp\Service\OAuth\OAuthException;

/**
 * Connection status of the logged-in admin user for one OAuth server, with a connect/disconnect button.
 * The server code comes from <attribute type="server_code"> on the field in system.xml.
 */
class OAuthConnection extends Field
{
    /**
     * @param Context $context
     * @param ConnectionService $connectionService
     * @param CurrentAdminUser $currentAdminUser
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly ConnectionService $connectionService,
        private readonly CurrentAdminUser $currentAdminUser,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $fieldConfig = (array)$element->getData('field_config');
        $userId = (int)$this->currentAdminUser->getId();
        try {
            $server = $this->connectionService->getServer((string)($fieldConfig['server_code'] ?? ''));
        } catch (OAuthException $e) {
            return $this->escapeHtml($e->getMessage());
        }

        if (!$server->isEnabled()) {
            return $this->escapeHtml(__('Enable the server, enter its URL and save the configuration first.'));
        }

        $params = ['server' => $server->getCode()];
        if ($userId > 0 && $this->connectionService->isConnected($server, $userId)) {
            return $this->status(
                (string)__('Connected with your own account. Only you can use it.'),
                $this->getUrl('magomcp/oauth/disconnect', $params),
                (string)__('Disconnect'),
                'action-default'
            );
        }

        return $this->status(
            (string)__('Not connected. You will be asked to log in at %1.', $server->getLabel()),
            $this->getUrl('magomcp/oauth/connect', $params),
            (string)__('Connect %1', $server->getLabel()),
            'action-default primary'
        );
    }

    private function status(string $message, string $url, string $label, string $class): string
    {
        return '<p>' . $this->escapeHtml($message) . '</p>'
            . '<a class="' . $class . '" href="' . $this->escapeUrl($url) . '">' . $this->escapeHtml($label) . '</a>';
    }
}
