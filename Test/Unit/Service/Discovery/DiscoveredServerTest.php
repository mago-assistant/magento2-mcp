<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Discovery;

use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DiscoveredServerTest extends TestCase
{
    #[Test]
    public function hyphensBecomeUnderscores(): void
    {
        self::assertSame('my_server', DiscoveredServer::normaliseName('my-server'));
        self::assertSame('my_server', DiscoveredServer::normaliseName('my_server'));
        self::assertSame('acme_tools2', DiscoveredServer::normaliseName('acme-tools2'));
        self::assertSame('widget', DiscoveredServer::normaliseName('Magento Widget'));
        self::assertSame('weird', DiscoveredServer::normaliseName('--weird--'));
        self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', DiscoveredServer::normaliseName('A.B:C/D-E'));
    }

    #[Test]
    public function prefixesAndSuffixesAreStrippedAfterNormalising(): void
    {
        self::assertSame('widget', DiscoveredServer::normaliseName('magento-widget'));
        self::assertSame('widget', DiscoveredServer::normaliseName('magento2_widget'));
        self::assertSame('widget', DiscoveredServer::normaliseName('module-widget-mcp'));
        self::assertSame('widget', DiscoveredServer::normaliseName('widget-mcp-server'));
        self::assertSame('mcp', DiscoveredServer::normaliseName('mcp'), 'a name that is only a suffix is kept');
    }
}
