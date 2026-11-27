<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Worker;

use MagoAssistant\Mcp\Service\Mcp\McpProcessException;

/**
 * Nothing answers as the MCP worker at the configured address.
 */
class WorkerUnavailableException extends McpProcessException
{
    public function __construct(string $address)
    {
        parent::__construct(sprintf(
            'Local MCP servers run through the MCP worker, which is not answering at %s. Cron starts it within a '
            . 'minute while a local server is enabled; to start it now run bin/magento mago:mcp:serve.',
            $address
        ));
    }
}
