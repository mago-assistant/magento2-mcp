<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Catalog;

use MagoAssistant\Mcp\Service\Catalog\DataAcl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DataAclTest extends TestCase
{
    /**
     * @return iterable<string,array{0:string,1:?string}>
     */
    public static function names(): iterable
    {
        yield 'customer' => ['customer-get', 'Magento_Customer::manage'];
        yield 'customer list' => ['customer_list', 'Magento_Customer::manage'];
        yield 'address' => ['customer-address-create', 'Magento_Customer::manage'];
        yield 'subscriber' => ['subscriber-export', 'Magento_Customer::manage'];
        yield 'order' => ['order-get', 'Magento_Sales::sales_order'];
        yield 'customer orders is a sales tool' => ['customer-orders', 'Magento_Sales::sales_order'];
        yield 'invoice' => ['invoice-list', 'Magento_Sales::sales_order'];
        yield 'creditmemo' => ['creditmemo-create', 'Magento_Sales::sales_order'];
        yield 'cart' => ['cart-inspect', 'Magento_Sales::sales_order'];
        yield 'review' => ['review-list', 'Magento_Review::reviews_all'];
        yield 'configuration' => ['configuration-get', 'Magento_Config::config'];
        yield 'magerun config' => ['config_store_get', 'Magento_Config::config'];
        yield 'product' => ['product-list', null];
        yield 'code' => ['code-runner', null];
        yield 'whole segments only' => ['preorder-list', null];
        yield 'empty' => ['', null];
    }

    #[Test]
    #[DataProvider('names')]
    public function mapsTheDataWordOfANameToItsResource(string $name, ?string $expected): void
    {
        self::assertSame($expected, (new DataAcl())->resourceFor($name));
    }

    #[Test]
    public function overridesAddChangeAndDropWords(): void
    {
        $acl = new DataAcl(['ticket' => 'Vendor_Helpdesk::tickets', 'Order' => 'Vendor_Oms::orders', 'review' => '']);

        self::assertSame('Vendor_Helpdesk::tickets', $acl->resourceFor('ticket-get'));
        self::assertSame('Vendor_Oms::orders', $acl->resourceFor('order-get'), 'a word is matched case-insensitively');
        self::assertNull($acl->resourceFor('review-list'), 'an empty resource drops the default');
        self::assertSame('Magento_Customer::manage', $acl->resourceFor('customer-get'), 'other defaults stay');
    }
}
