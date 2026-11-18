<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Model\Cache\Type;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * A cache type of its own so tool lists show in cache:status and clean with cache:clean mago_mcp.
 */
class McpTools extends TagScope
{
    public const TYPE_IDENTIFIER = 'mago_mcp';
    public const CACHE_TAG = 'MAGO_MCP';

    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
