<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Model;

use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    #[Test]
    public function readsEveryFieldFromItsPath(): void
    {
        $config = new Config(new FakeScopeConfig([
            'mago/mcp/enabled' => '1',
            'mago/mcp/scan_mcp_json' => '0',
            'mago/mcp/process_timeout' => '15',
            'mago/mcp/cache_lifetime' => '120',
            'mago/mcp/max_result_chars' => '500',
        ]));

        self::assertTrue($config->isEnabled());
        self::assertFalse($config->isScanMcpJsonEnabled());
        self::assertSame(15, $config->getProcessTimeout());
        self::assertSame(120, $config->getCacheLifetime());
        self::assertSame(500, $config->getMaxResultChars());
    }

    #[Test]
    public function fallsBackToDefaultsWhenUnsetOrNonPositive(): void
    {
        $config = new Config(new FakeScopeConfig(['mago/mcp/process_timeout' => '0']));

        self::assertSame(60, $config->getProcessTimeout());
        self::assertSame(3600, $config->getCacheLifetime());
        self::assertSame(16000, $config->getMaxResultChars());
    }
}
