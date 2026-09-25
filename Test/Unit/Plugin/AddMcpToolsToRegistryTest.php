<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Plugin;

use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Skills\PermissionChecker;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mcp\Plugin\AddMcpToolsToRegistry;
use MagoAssistant\Mcp\Service\Client;
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
        $this->mcpTool = new McpTool(new FakeMcpServer(), $this->createStub(Client::class), [
            ['name' => 'read_wiki', 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'write_wiki'],
        ]);
        $provider = $this->createStub(ToolProvider::class);
        $provider->method('getTools')->willReturn([$this->mcpTool]);
        $this->plugin = new AddMcpToolsToRegistry($provider);
    }

    #[Test]
    public function addsMcpToolsToAllToolsWithoutReplacingBuiltInOnes(): void
    {
        $builtIn = $this->createStub(ToolInterface::class);

        $result = $this->plugin->afterGetAllTools($this->registry([]), ['mcp_test' => $builtIn, 'cms_data' => $builtIn]);

        self::assertSame($builtIn, $result['mcp_test']);
        self::assertCount(2, $result);
        self::assertSame($this->mcpTool, $this->plugin->afterGetAllTools($this->registry([]), [])['mcp_test']);
    }

    #[Test]
    public function offersAnMcpToolWithAReadGrantWhenItHasAReadOnlyAction(): void
    {
        $enabled = $this->plugin->afterGetEnabledTools($this->registry(['mcp_test:read' => true]), [], self::ADMIN_ID);

        self::assertSame(['mcp_test' => $this->mcpTool], $enabled);
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

        self::assertSame($builtIn, $this->plugin->afterGetToolByName($registry, $builtIn, 'mcp_test'));
        self::assertSame($this->mcpTool, $this->plugin->afterGetToolByName($registry, null, 'mcp_test'));
        self::assertNull($this->plugin->afterGetToolByName($registry, null, 'unknown'));
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
