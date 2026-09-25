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
use MagoAssistant\Mcp\Service\Discovery\ServerScanner;

class Rescan extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mcp::manage';

    public function __construct(
        Context $context,
        private readonly ServerScanner $scanner,
        private readonly ServerRepositoryInterface $servers,
        private readonly ToolCatalog $catalog
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $result = $this->servers->merge($this->scanner->scan());
        foreach ($result['names'] as $name) {
            $this->catalog->refresh($name);
        }
        $this->messageManager->addSuccessMessage(
            __(
                'Discovery inserted %1, updated %2 and flagged %3 missing.',
                $result['inserted'],
                $result['updated'],
                $result['missing']
            )
        );

        return $this->resultRedirectFactory->create()->setPath('mago_mcp/servers/index');
    }
}
