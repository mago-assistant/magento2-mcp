<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Plugin;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as MagoConfig;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Plugin\ToolRegistryMcpSkills;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Tool\Mcp\Executor;
use MagoAssistant\Mcp\Service\Tool\Mcp\McpSkill;
use MagoAssistant\Mcp\Service\Tool\Mcp\SkillRegistry;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeMagoTool;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeMcpClient;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakePermissionChecker;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeServerRepository;
use MagoAssistant\Mcp\Test\Unit\Fakes\PluggedToolRegistry;
use MagoAssistant\Mcp\Test\Unit\Fakes\ThrowingSkillRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolRegistryMcpSkillsTest extends TestCase
{
    private FakeServerRepository $servers;
    private FakeMcpClient $client;
    private FakeLogger $log;
    private FakeMagoTool $magoTool;

    protected function setUp(): void
    {
        $this->servers = new FakeServerRepository();
        $this->client = new FakeMcpClient();
        $this->log = new FakeLogger();
        $this->magoTool = new FakeMagoTool();
        $this->client->tools['demo'] = [
            ['name' => 'product-list', 'description' => 'List products.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'product-delete', 'description' => 'Delete a product.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'code-runner', 'description' => 'Run PHP.', 'inputSchema' => ['type' => 'object']],
        ];
        $this->servers->add('demo', true);
    }

    private function errorLogger(): ErrorLogger
    {
        return new ErrorLogger($this->log, new Json());
    }

    private function skillRegistry(): SkillRegistry
    {
        $config = new Config(new FakeScopeConfig([
            'mago/mcp/enabled' => '1',
            'mago/mcp/process_timeout' => '5',
            'mago/mcp/cache_lifetime' => '60',
            'mago/mcp/max_result_chars' => '16000',
        ]));
        $catalog = new ToolCatalog(
            $this->servers,
            $this->client,
            new FakeCache(),
            $config,
            new ModeClassifier(),
            $this->errorLogger()
        );
        $executor = new Executor(
            $catalog,
            $this->client,
            $config,
            new DebugLogger($this->log, new Json()),
            $this->errorLogger(),
            $this->createStub(MagoConfig::class)
        );

        return new SkillRegistry($catalog, $executor, $this->errorLogger(), new ModeClassifier());
    }

    private function plugin(?SkillRegistry $skills = null, ?FakeAuthorization $authorization = null): ToolRegistryMcpSkills
    {
        return new ToolRegistryMcpSkills(
            $skills ?? $this->skillRegistry(),
            $this->errorLogger(),
            $authorization ?? new FakeAuthorization(['MagoAssistant_Mcp::use', 'MagoAssistant_Mcp::use_write'])
        );
    }

    /**
     * @param ToolInterface[] $tools
     * @return string[]
     */
    private static function names(array $tools): array
    {
        return array_values(array_map(static fn (ToolInterface $t): string => $t->getName(), $tools));
    }

    #[Test]
    public function allToolsGainEveryMcpSkill(): void
    {
        $registry = new ToolRegistry(new FakePermissionChecker(), [$this->magoTool]);

        $all = $this->plugin()->afterGetAllTools($registry, $registry->getAllTools());

        self::assertSame(
            ['sales_data', 'demo__product-list', 'demo__product-delete', 'demo__code-runner'],
            self::names($all)
        );
    }

    #[Test]
    public function toolByNameFallsThroughToMcpSkills(): void
    {
        $registry = new ToolRegistry(new FakePermissionChecker(), [$this->magoTool]);
        $plugin = $this->plugin();

        self::assertInstanceOf(McpSkill::class, $plugin->afterGetToolByName($registry, null, 'demo__product-list'));
        self::assertInstanceOf(McpSkill::class, $plugin->afterGetToolByName($registry, null, 'demo__code-runner'));
        self::assertSame($this->magoTool, $plugin->afterGetToolByName($registry, $this->magoTool, 'sales_data'));
        self::assertNull($plugin->afterGetToolByName($registry, null, 'unknown'));
    }

    #[Test]
    public function everySkillIsEnabledWhenTheCheckerAllowsIt(): void
    {
        $checker = new FakePermissionChecker();
        $registry = new ToolRegistry($checker, [$this->magoTool]);

        $enabled = $this->plugin()->afterGetEnabledTools($registry, $registry->getEnabledTools(7), 7);

        self::assertSame(['sales_data', 'demo__product-list', 'demo__product-delete', 'demo__code-runner'], array_keys($enabled));
        self::assertContains([7, 'demo__product-list', 'read'], $checker->asked);
        self::assertContains([7, 'demo__product-delete', 'write'], $checker->asked);
        self::assertContains([7, 'demo__code-runner', 'write'], $checker->asked);
    }

    #[Test]
    public function enabledToolsRespectTheSubjectsPermissionCheck(): void
    {
        $checker = new FakePermissionChecker(false);
        $registry = new ToolRegistry($checker, [$this->magoTool]);

        $enabled = $this->plugin()->afterGetEnabledTools($registry, $registry->getEnabledTools(7), 7);

        self::assertArrayHasKey('demo__product-list', $enabled);
        self::assertArrayNotHasKey('demo__product-delete', $enabled, 'write denied by the checker');
        self::assertArrayNotHasKey('demo__code-runner', $enabled, 'write denied by the checker');
        self::assertArrayHasKey('sales_data', $enabled);
    }

    #[Test]
    public function withoutAPermissionCheckerEverySkillIsEnabled(): void
    {
        $registry = new ToolRegistry(null, [$this->magoTool]);

        $enabled = $this->plugin()->afterGetEnabledTools($registry, $registry->getEnabledTools(), null);

        self::assertSame(['sales_data', 'demo__product-list', 'demo__product-delete', 'demo__code-runner'], array_keys($enabled));
    }

    #[Test]
    public function definitionsAndGetToolGoThroughThePluginsAsTheInterceptorWould(): void
    {
        $registry = new PluggedToolRegistry(new FakePermissionChecker(false), [$this->magoTool], $this->plugin());

        $definitions = array_column($registry->getToolDefinitions(7), 'name');

        self::assertSame(['sales_data', 'demo__product-list'], $definitions);
        self::assertInstanceOf(McpSkill::class, $registry->getTool('demo__product-list', 7));
        self::assertNull($registry->getTool('demo__product-delete', 7), 'write denied');
        self::assertNull($registry->getTool('demo__code-runner', 7), 'write denied');
        self::assertInstanceOf(McpSkill::class, $registry->getToolByName('demo__code-runner'));

        $granted = new PluggedToolRegistry(new FakePermissionChecker(), [$this->magoTool], $this->plugin());
        $write = $granted->getTool('demo__code-runner', 7);
        self::assertInstanceOf(McpSkill::class, $write, 'a write grant makes a write usable');
        self::assertFalse($write->isReadOnly());
    }

    #[Test]
    public function aCheckerDenyingReadsLeavesReadSkillsOut(): void
    {
        $registry = new ToolRegistry(new FakePermissionChecker(true, false), [$this->magoTool]);

        $enabled = $this->plugin()->afterGetEnabledTools($registry, $registry->getEnabledTools(7), 7);

        self::assertArrayNotHasKey('demo__product-list', $enabled);
        self::assertArrayNotHasKey('sales_data', $enabled);
        self::assertSame(['demo__product-delete', 'demo__code-runner'], array_keys($enabled), 'the write grant still applies');
    }

    #[Test]
    public function aRoleWithoutTheWriteResourceIsOfferedOnlyReads(): void
    {
        $registry = new ToolRegistry(new FakePermissionChecker(), [$this->magoTool]);
        $plugin = $this->plugin(null, new FakeAuthorization(['MagoAssistant_Mcp::use']));

        $enabled = $plugin->afterGetEnabledTools($registry, $registry->getEnabledTools(7), 7);

        self::assertArrayHasKey('demo__product-list', $enabled);
        self::assertArrayNotHasKey('demo__product-delete', $enabled, 'the role lacks MCP Tools - Write');
        self::assertArrayNotHasKey('demo__code-runner', $enabled, 'the role lacks MCP Tools - Write');
        self::assertArrayHasKey('sales_data', $enabled, "Mago's own skills are not filtered by the addon");
    }

    #[Test]
    public function aRoleWithoutAnyMcpResourceIsOfferedNothing(): void
    {
        $registry = new ToolRegistry(new FakePermissionChecker(), [$this->magoTool]);
        $plugin = $this->plugin(null, new FakeAuthorization([]));

        $enabled = $plugin->afterGetEnabledTools($registry, $registry->getEnabledTools(7), 7);

        self::assertSame(['sales_data'], array_keys($enabled));
    }

    #[Test]
    public function magosOwnSkillWinsANameClashInEnabledTools(): void
    {
        $magoNamesake = new FakeMagoTool('demo__product-list');
        $registry = new ToolRegistry(new FakePermissionChecker(), [$magoNamesake]);

        $enabled = $this->plugin()->afterGetEnabledTools($registry, $registry->getEnabledTools(7), 7);

        self::assertSame($magoNamesake, $enabled['demo__product-list']);
        self::assertSame(['demo__product-list', 'demo__product-delete', 'demo__code-runner'], array_keys($enabled));
    }

    #[Test]
    public function aFailingCatalogLeavesMagosToolsUntouched(): void
    {
        $registry = new ToolRegistry(new FakePermissionChecker(), [$this->magoTool]);
        $plugin = $this->plugin(new ThrowingSkillRegistry());
        $magoAll = $registry->getAllTools();
        $magoEnabled = $registry->getEnabledTools(7);

        self::assertSame($magoAll, $plugin->afterGetAllTools($registry, $magoAll));
        self::assertNull($plugin->afterGetToolByName($registry, null, 'demo__product-list'));
        self::assertSame($this->magoTool, $plugin->afterGetToolByName($registry, $this->magoTool, 'sales_data'));
        self::assertSame($magoEnabled, $plugin->afterGetEnabledTools($registry, $magoEnabled, 7));
        self::assertCount(3, $this->log->entries);
        self::assertStringContainsString(
            'MCP skills unavailable: {"error":"down","exception":"RuntimeException"}',
            $this->log->messages()
        );
    }
}
