<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Discovery;

use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DiscoveredServerFieldsTest extends TestCase
{
    #[Test]
    public function aStdioServerKeepsItsDefaultsInTheRow(): void
    {
        $row = (new DiscoveredServer('demo', ['php', 'x'], ['A' => '1'], '/app', DiscoveredServer::SOURCE_COMPOSER))->toRow();

        self::assertSame([
            'name' => 'demo', 'command' => ['php', 'x'], 'env' => ['A' => '1'], 'cwd' => '/app', 'source' => 'composer',
            'label' => '', 'transport' => 'stdio', 'url' => '', 'auth_type' => 'none',
            'allowed_tools' => [], 'timeout' => null, 'output_public' => false, 'replaces_skill' => '',
            'read_only' => false,
        ], $row);
        self::assertArrayNotHasKey('bearer_token', $row, 'no token from the source leaves a stored token alone on rescan');
    }

    #[Test]
    public function anHttpServerCarriesItsFields(): void
    {
        $server = new DiscoveredServer(
            'remote',
            [],
            [],
            null,
            DiscoveredServer::SOURCE_MODULE,
            label: 'Remote',
            transport: 'http',
            url: 'https://example.test/mcp',
            authType: 'bearer',
            bearerToken: 't0k',
            allowedTools: ['a'],
            timeout: 20,
            outputPublic: true,
            replacesSkill: 'remote'
        );
        $row = $server->toRow();

        self::assertSame('module', $row['source']);
        self::assertSame('http', $row['transport']);
        self::assertSame('https://example.test/mcp', $row['url']);
        self::assertSame('bearer', $row['auth_type']);
        self::assertSame('t0k', $row['bearer_token']);
        self::assertSame(['a'], $row['allowed_tools']);
        self::assertSame(20, $row['timeout']);
        self::assertTrue($row['output_public']);
        self::assertSame('remote', $row['replaces_skill']);
        self::assertSame('Remote', $row['label']);
        self::assertTrue((new DiscoveredServer('r', [], [], null, 'module', readOnly: true))->toRow()['read_only']);
    }
}
