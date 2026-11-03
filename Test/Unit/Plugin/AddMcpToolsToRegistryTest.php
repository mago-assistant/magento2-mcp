<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Plugin;

use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Skills\PermissionChecker;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mcp\Api\AuthenticatorInterface;
use MagoAssistant\Mcp\Plugin\AddMcpToolsToRegistry;
use MagoAssistant\Mcp\Service\Client;
use MagoAssistant\Mcp\Service\InstructionGate;
use MagoAssistant\Mcp\Service\McpTool;
use MagoAssistant\Mcp\Service\ToolProvider;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeMcpServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AddMcpToolsToRegistryTest extends TestCase
{
    private const ADMIN_ID = 7;

    private McpTool $mcpTool;
    private AddMcpToolsToRegistry $plugin;

    protected function setUp(): void
    {
        $this->mcpTool = new McpTool(
            new FakeMcpServer(),
            $this->createStub(Client::class),
            ['name' => 'read_wiki', 'annotations' => ['readOnlyHint' => true]],
            new InstructionGate()
        );
        $provider = $this->createStub(ToolProvider::class);
        $provider->method('getTools')->willReturn([$this->mcpTool]);
        $this->plugin = new AddMcpToolsToRegistry($provider);
    }

    #[Test]
    public function addsMcpToolsToAllToolsWithoutReplacingBuiltInOnes(): void
    {
        $builtIn = $this->createStub(ToolInterface::class);

        $name = 'mcp_test__read_wiki';
        $result = $this->plugin->afterGetAllTools($this->registry([]), [$name => $builtIn, 'cms_data' => $builtIn]);

        self::assertSame($builtIn, $result[$name]);
        self::assertCount(2, $result);
        self::assertSame($this->mcpTool, $this->plugin->afterGetAllTools($this->registry([]), [])[$name]);
    }

    #[Test]
    public function offersAReadOnlyMcpToolWithAReadGrant(): void
    {
        $registry = $this->registry(['mcp_test__read_wiki:read' => true]);
        $enabled = $this->plugin->afterGetEnabledTools($registry, [], self::ADMIN_ID);

        self::assertSame(['mcp_test__read_wiki' => $this->mcpTool], $enabled);
    }

    #[Test]
    public function hidesAnMcpToolFromAUserWithoutAGrant(): void
    {
        self::assertSame([], $this->plugin->afterGetEnabledTools($this->registry([]), [], self::ADMIN_ID));
    }

    #[Test]
    public function findsAnMcpToolByNameOnlyWhenNoBuiltInMatched(): void
    {
        $builtIn = $this->createStub(ToolInterface::class);
        $registry = $this->registry([]);

        self::assertSame($builtIn, $this->plugin->afterGetToolByName($registry, $builtIn, 'mcp_test__read_wiki'));
        self::assertSame($this->mcpTool, $this->plugin->afterGetToolByName($registry, null, 'mcp_test__read_wiki'));
        self::assertNull($this->plugin->afterGetToolByName($registry, null, 'unknown'));
    }

    #[Test]
    public function offersAnOAuthServerOnlyToTheUserWhoConnectedAndDropsTheReplacedSkill(): void
    {
        $authenticator = $this->createStub(AuthenticatorInterface::class);
        $authenticator->method('hasCredentials')->willReturnCallback(static fn (?int $userId): bool => $userId === 1);
        $tool = new McpTool(
            new FakeMcpServer('rumvision', $authenticator, [], [], 'rumvision'),
            $this->createStub(Client::class),
            ['name' => 'query-metrics-tool', 'annotations' => ['readOnlyHint' => true]],
            new InstructionGate()
        );
        $provider = $this->createStub(ToolProvider::class);
        $provider->method('getTools')->willReturn([$tool]);
        $plugin = new AddMcpToolsToRegistry($provider);
        $skill = $this->createStub(ToolInterface::class);
        $builtIn = ['rumvision' => $skill, 'sales_data' => $skill];
        $registry = $this->registry(['mcp_rumvision__query_metrics_tool:read' => true]);

        $connected = $plugin->afterGetEnabledTools($registry, $builtIn, 1);
        $other = $plugin->afterGetEnabledTools($registry, $builtIn, 2);

        self::assertSame(['sales_data', 'mcp_rumvision__query_metrics_tool'], array_keys($connected));
        self::assertSame(['rumvision', 'sales_data'], array_keys($other));
    }

    /**
     * @param array<string, bool> $grants "tool:read|write" => allowed
     */
    private function registry(array $grants): ToolRegistry
    {
        $checker = $this->createStub(PermissionChecker::class);
        $checker->method('isAllowed')->willReturnCallback(
            static fn (int $adminUserId, string $tool, string $action): bool => $grants[$tool . ':' . $action] ?? false
        );

        return new ToolRegistry($checker);
    }
}
