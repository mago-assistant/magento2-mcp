<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;

/**
 * Edit, Enable or Disable, Delete (manual rows), and the OAuth connection, as Mago's grids offer theirs:
 * links for pages, posts with a confirmation for anything that changes state.
 */
class ServerActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['name'])) {
                continue;
            }
            $name = (string)$item['name'];
            $enabled = ($item['enabled'] ?? '0') === '1';
            $actions = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl('mago_mcp/servers/edit', ['name' => $name]),
                    'label' => __('Edit'),
                ],
                'toggle' => [
                    'href' => $this->urlBuilder->getUrl('mago_mcp/servers/toggle', ['name' => $name]),
                    'label' => $enabled ? __('Disable') : __('Enable'),
                    'post' => true,
                    'confirm' => $enabled ? null : [
                        'title' => __('Enable MCP Server'),
                        'message' => __(
                            'Expose the tools of "%1" to the assistant? Tools that run code, SQL or commands stay '
                            . 'withheld until you tick them on the server\'s edit page.',
                            $name
                        ),
                    ],
                ],
            ];
            if ($actions['toggle']['confirm'] === null) {
                unset($actions['toggle']['confirm']);
            }
            if (($item['has_connection'] ?? '0') === '1') {
                $connected = ($item['connected'] ?? '0') === '1';
                $actions['connection'] = [
                    'href' => $this->urlBuilder->getUrl(
                        $connected ? 'mago_mcp/oauth/disconnect' : 'mago_mcp/oauth/connect',
                        ['name' => $name]
                    ),
                    'label' => $connected ? __('Disconnect my account') : __('Connect my account'),
                ];
            }
            if (($item['source'] ?? '') === DiscoveredServer::SOURCE_MANUAL) {
                $actions['delete'] = [
                    'href' => $this->urlBuilder->getUrl('mago_mcp/servers/delete', ['name' => $name]),
                    'label' => __('Delete'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Delete MCP Server'),
                        'message' => __('Delete "%1", and every admin\'s connection to it?', $name),
                    ],
                ];
            }
            $item[$this->getData('name')] = $actions;
        }

        return $dataSource;
    }
}
