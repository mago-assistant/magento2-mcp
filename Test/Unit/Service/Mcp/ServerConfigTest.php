<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Mcp;

use MagoAssistant\Mcp\Service\Discovery\ServerDefinition;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServerConfigTest extends TestCase
{
    #[Test]
    public function aStdioRowKeepsItsDefaults(): void
    {
        $config = ServerConfig::fromRow([
            'name' => 'demo', 'command' => ['php', 'x.php'], 'env' => ['A' => '1'], 'cwd' => null,
        ], 45);

        self::assertSame('demo', $config->name);
        self::assertSame(['php', 'x.php'], $config->command);
        self::assertSame(ServerConfig::TRANSPORT_STDIO, $config->transport);
        self::assertSame(ServerConfig::AUTH_NONE, $config->authType);
        self::assertSame(45, $config->timeout, 'a null timeout column uses the given default');
        self::assertFalse($config->outputPublic);
        self::assertSame([], $config->allowedTools);
        self::assertSame('demo', $config->label(), 'no label falls back to the name');
    }

    #[Test]
    public function anHttpRowCarriesItsFields(): void
    {
        $config = ServerConfig::fromRow([
            'name' => 'remote', 'command' => [], 'env' => [], 'cwd' => null, 'label' => 'Remote',
            'transport' => 'http', 'url' => 'https://example.test/mcp', 'auth_type' => 'bearer',
            'allowed_tools' => ['a', 'b'], 'timeout' => 20, 'output_public' => true, 'replaces_skill' => 'remote',
        ], 45);

        self::assertSame(ServerConfig::TRANSPORT_HTTP, $config->transport);
        self::assertSame('https://example.test/mcp', $config->url);
        self::assertSame(ServerConfig::AUTH_BEARER, $config->authType);
        self::assertSame(['a', 'b'], $config->allowedTools);
        self::assertSame(20, $config->timeout);
        self::assertTrue($config->outputPublic);
        self::assertSame('remote', $config->replacesSkill);
        self::assertSame('Remote', $config->label());
    }

    #[Test]
    public function aDefinitionSuppliesTheNonStorableFields(): void
    {
        $definition = new ServerDefinition(
            'remote',
            fieldClassificationOverrides: ['who' => ['*' => ['public']]],
            errorHints: ['x' => 'Try y.']
        );

        $config = ServerConfig::fromRow(['name' => 'remote', 'command' => [], 'transport' => 'http'], 10, $definition);

        self::assertSame(['who' => ['*' => ['public']]], $config->fieldClassificationOverrides);
        self::assertSame(['x' => 'Try y.'], $config->errorHints);
        self::assertSame([], ServerConfig::fromRow(['name' => 'remote', 'command' => []], 10)->errorHints);
    }

    #[Test]
    public function unknownTransportAndAuthValuesAreKeptForTheResolverToReject(): void
    {
        $config = ServerConfig::fromRow(['name' => 'x', 'command' => [], 'transport' => 'carrier-pigeon', 'auth_type' => 'magic'], 1);

        self::assertSame('carrier-pigeon', $config->transport);
        self::assertSame('magic', $config->authType);
    }
}
