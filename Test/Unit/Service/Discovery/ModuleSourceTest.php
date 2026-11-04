<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Discovery;

use MagoAssistant\Mcp\Service\Discovery\DefinitionRegistry;
use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;
use MagoAssistant\Mcp\Service\Discovery\ModuleSource;
use MagoAssistant\Mcp\Service\Discovery\ServerDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModuleSourceTest extends TestCase
{
    #[Test]
    public function aDefinitionBecomesAModuleRow(): void
    {
        $definition = new ServerDefinition(
            'Analytics-Cloud',
            label: 'Analytics',
            url: 'https://mcp.example.test/mcp',
            authType: 'bearer',
            bearerToken: 'k',
            allowedTools: ['a', 'b'],
            timeout: 30,
            outputPublic: true,
            replacesSkill: 'analytics',
            fieldClassificationOverrides: ['who' => ['*' => ['public'], 'email' => ['strip']]],
            errorHints: ['no such' => 'List first.']
        );
        $registry = new DefinitionRegistry([$definition]);

        $servers = (new ModuleSource($registry))->discover();

        self::assertCount(1, $servers);
        self::assertSame('analytics_cloud', $servers[0]->name, 'normalised like every other source');
        self::assertSame(DiscoveredServer::SOURCE_MODULE, $servers[0]->source);
        self::assertSame('http', $servers[0]->transport);
        self::assertSame('https://mcp.example.test/mcp', $servers[0]->url);
        self::assertSame('bearer', $servers[0]->authType);
        self::assertSame('k', $servers[0]->bearerToken);
        self::assertSame(['a', 'b'], $servers[0]->allowedTools);
        self::assertSame(30, $servers[0]->timeout);
        self::assertTrue($servers[0]->outputPublic);
        self::assertSame('analytics', $servers[0]->replacesSkill);
        self::assertSame($definition, $registry->get('analytics-cloud'), 'looked up by any spelling of the name');
        self::assertNull($registry->get('other'));
    }

    #[Test]
    public function aStdioDefinitionCarriesACommand(): void
    {
        $servers = (new ModuleSource(new DefinitionRegistry([
            new ServerDefinition('local', transport: 'stdio', command: ['php', 'bin/x'], env: ['A' => '1'], cwd: '/app'),
        ])))->discover();

        self::assertSame('stdio', $servers[0]->transport);
        self::assertSame(['php', 'bin/x'], $servers[0]->command);
        self::assertSame(['A' => '1'], $servers[0]->env);
        self::assertSame('/app', $servers[0]->cwd);
    }

    #[Test]
    public function aDefinitionMayDeclareTheServerReadOnly(): void
    {
        $servers = (new ModuleSource(new DefinitionRegistry([new ServerDefinition('safe', readOnly: true)])))->discover();

        self::assertTrue($servers[0]->readOnly);
    }

    #[Test]
    public function aDefinitionWithACommandAndNoUrlIsStdioWithoutSayingSo(): void
    {
        $servers = (new ModuleSource(new DefinitionRegistry([
            new ServerDefinition('local', command: ['php', 'bin/x']),
            new ServerDefinition('remote', url: 'https://example.test/mcp'),
        ])))->discover();

        self::assertSame('stdio', $servers[0]->transport);
        self::assertSame('http', $servers[1]->transport);
    }
}
