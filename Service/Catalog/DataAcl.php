<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Catalog;

/**
 * The Magento ACL resource that guards the data an MCP tool deals in, from the tool's name. Mago's own
 * skills name such a resource (an order skill names Magento_Sales::sales_order) and ToolAccess refuses
 * the call without it; an MCP tool knows no Magento resource, so before this an admin Magento keeps
 * out of Customers or Orders could read every customer and order through any MCP server that returns
 * them. The resource is an addition to the skill's own MagoAssistant_Mcp::use / use_write / execute,
 * never a replacement (ToolAccess requires both), and sales words are matched before customer words so
 * customer-orders is a sales tool and customer-address-get a customer tool.
 *
 * The map is extended or changed from di.xml ("" drops a default):
 *
 *     <type name="MagoAssistant\Mcp\Service\Catalog\DataAcl">
 *         <arguments>
 *             <argument name="overrides" xsi:type="array">
 *                 <item name="ticket" xsi:type="string">Vendor_Helpdesk::tickets</item>
 *             </argument>
 *         </arguments>
 *     </type>
 */
class DataAcl
{
    /** Word => resource, in match priority order. */
    private const DEFAULTS = [
        'order' => 'Magento_Sales::sales_order', 'orders' => 'Magento_Sales::sales_order',
        'invoice' => 'Magento_Sales::sales_order', 'invoices' => 'Magento_Sales::sales_order',
        'shipment' => 'Magento_Sales::sales_order', 'shipments' => 'Magento_Sales::sales_order',
        'creditmemo' => 'Magento_Sales::sales_order', 'creditmemos' => 'Magento_Sales::sales_order',
        'quote' => 'Magento_Sales::sales_order', 'quotes' => 'Magento_Sales::sales_order',
        'cart' => 'Magento_Sales::sales_order',
        'customer' => 'Magento_Customer::manage', 'customers' => 'Magento_Customer::manage',
        'address' => 'Magento_Customer::manage', 'addresses' => 'Magento_Customer::manage',
        'email' => 'Magento_Customer::manage', 'phone' => 'Magento_Customer::manage',
        'account' => 'Magento_Customer::manage', 'accounts' => 'Magento_Customer::manage',
        'user' => 'Magento_Customer::manage', 'users' => 'Magento_Customer::manage',
        'subscriber' => 'Magento_Customer::manage', 'subscribers' => 'Magento_Customer::manage',
        'wishlist' => 'Magento_Customer::manage',
        'review' => 'Magento_Review::reviews_all', 'reviews' => 'Magento_Review::reviews_all',
        'configuration' => 'Magento_Config::config', 'config' => 'Magento_Config::config',
        // Diagnostics that print connection details, environment or raw log lines: System, not everyone.
        'db' => 'Magento_Backend::system', 'database' => 'Magento_Backend::system',
        'env' => 'Magento_Backend::system', 'environment' => 'Magento_Backend::system',
        'log' => 'Magento_Backend::system', 'logs' => 'Magento_Backend::system',
        'report' => 'Magento_Backend::system', 'reports' => 'Magento_Backend::system',
    ];

    /** @var array<string,string> */
    private readonly array $map;

    /**
     * @param array<string,string> $overrides word => resource; an empty resource drops the word
     */
    public function __construct(array $overrides = [])
    {
        $map = self::DEFAULTS;
        foreach ($overrides as $word => $resource) {
            $word = strtolower(trim((string)$word));
            if ($word === '') {
                continue;
            }
            if ((string)$resource === '') {
                unset($map[$word]);
            } else {
                $map[$word] = (string)$resource;
            }
        }
        $this->map = $map;
    }

    /**
     * The resource the tool's name points at, or null when its name carries no data word. Whole
     * segments only, split on "-" and "_": "preorder" is not "order".
     */
    public function resourceFor(string $toolName): ?string
    {
        $segments = array_values(array_filter(preg_split('/[-_]/', strtolower($toolName)) ?: []));
        foreach ($this->map as $word => $resource) {
            if (in_array($word, $segments, true)) {
                return $resource;
            }
        }

        return null;
    }
}
