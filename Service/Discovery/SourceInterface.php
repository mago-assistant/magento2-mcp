<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Discovery;

/**
 * One place MCP servers can be found. Sources never enable anything; they only report.
 */
interface SourceInterface
{
    /**
     * @return DiscoveredServer[]
     */
    public function discover(): array;
}
