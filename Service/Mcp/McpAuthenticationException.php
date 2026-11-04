<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Mcp;

/**
 * The server rejected the credentials, or none exist for this admin: not cached and not written to the
 * row, since the next admin may be connected.
 */
class McpAuthenticationException extends McpException
{
}
