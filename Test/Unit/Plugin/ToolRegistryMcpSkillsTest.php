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
use MagoAssistant\Mcp\Service\Discovery\DefinitionRegistry;
use MagoAssistant\Mcp\Service\Tool\Mcp\Executor;
use MagoAssistant\Mcp\Service\Tool\Mcp\McpSkill;
use MagoAssistant\Mcp\Service\Tool\Mcp\SkillRegistry;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeAuthenticatorResolver;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeMagoTool;
use MagoAssistant\Mcp\Service\Transport\TransportResolver;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeTransport;
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
    private FakeTransport $transport;
    private FakeLogger $log;
    private FakeMagoTool $magoTool;
    private FakeAuthenticatorResolver $authenticators;
    private ?ToolCatalog $catalog = null;
    private FakeCache $cache;

    protected function setUp(): void
    {
        $this->servers = new FakeServerRepository();
        $this->transport = new FakeTransport();
        $this->log = new FakeLogger();
        $this->magoTool = new FakeMagoTool();
        $this->authenticators = new FakeAuthenticatorResolver();
        $this->cache = new FakeCache();
        $this->transport->tools['demo'] = [
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
        $catalog = $this->catalog = new ToolCatalog(
            $this->servers,
            new TransportResolver(['stdio' => $this->transport, 'http' => $this->transport]),
            $this->cache,
            $config,
            new ModeClassifier(),
            $this->errorLogger(),
            new DefinitionRegistry(),
            $this->authenticators
        );
        $executor = new Executor(
            $catalog,
            new TransportResolver(['stdio' => $this->transport]),
            $config,
            new DebugLogger($this->log, new Json()),
            $this->errorLogger(),
            $this->createStub(MagoConfig::class)
        );

        return new SkillRegistry($catalog, $executor, $this->errorLogger(), new ModeClassifier());
    }

    private function plugin(?SkillRegistry $skills = null, ?FakeAuthorization $authorization = null): ToolRegistryMcpSkills
    {
        if ($skills === null || $this->catalog === null) {
            $real = $this->skillRegistry();
            $skills ??= $real;
        }

        return new ToolRegistryMcpSkills(
            $skills,
            $this->errorLogger(),
            $authorization ?? new FakeAuthorization(['MagoAssistant_Mcp::use', 'MagoAssistant_Mcp::use_write']),
            $this->catalog
        );
    }

    private function addOauthServer(string $replacesSkill = ''): void
    {
        $this->transport->tools['remote'] = [
            ['name' => 'metrics-get', 'description' => 'Get metrics.', 'inputSchema' => ['type' => 'object']],
        ];
        $this->servers->add('remote', true, 'module', [
            'transport' => 'http', 'auth_type' => 'oauth', 'command' => [], 'replaces_skill' => $replacesSkill,
        ]);
    }

    #[Test]
    public function anOauthServersToolsReachOnlyAConnectedAdmin(): void
    {
        $this->addOauthServer();
        $this->authenticators->credentials['remote:7'] = true;
        $plugin = $this->plugin();
        $registry = new PluggedToolRegistry(null, [$this->magoTool], $plugin);

        $forSeven = self::names($plugin->afterGetEnabledTools($registry, [], 7));
        $forEight = self::names($plugin->afterGetEnabledTools($registry, [], 8));
        $all = self::names($plugin->afterGetAllTools($registry, []));

        self::assertContains('mcp_remote__metrics_get', $forSeven);
        self::assertContains('mcp_demo__product_list', $forSeven);
        self::assertNotContains('mcp_remote__metrics_get', $forEight, 'no connection, not offered');
        self::assertContains('mcp_demo__product_list', $forEight, 'the stdio server needs no connection');
        self::assertContains('mcp_remote__metrics_get', $all, 'the cached list is visible on Skills & Permissions');
        self::assertNull($plugin->afterGetTool($registry, $registry->getToolByName('mcp_remote__metrics_get'), 'mcp_remote__metrics_get', 8), 'and blocked at execution for an unconnected admin');
        self::assertNotNull($plugin->afterGetTool($registry, $registry->getToolByName('mcp_remote__metrics_get'), 'mcp_remote__metrics_get', 7));
    }

    #[Test]
    public function aReplacedSkillIsHiddenAndBlockedForTheConnectedAdminOnly(): void
    {
        $this->addOauthServer('legacy');
        $this->authenticators->credentials['remote:7'] = true;
        $legacy = new FakeMagoTool('legacy');
        $plugin = $this->plugin();
        $registry = new PluggedToolRegistry(null, [$legacy], $plugin);

        $forSeven = $plugin->afterGetEnabledTools($registry, ['legacy' => $legacy], 7);
        $forEight = $plugin->afterGetEnabledTools($registry, ['legacy' => $legacy], 8);

        self::assertArrayNotHasKey('legacy', $forSeven, 'the server replaces the skill for the admin it is offered to');
        self::assertArrayHasKey('mcp_remote__metrics_get', $forSeven);
        self::assertArrayHasKey('legacy', $forEight, 'everyone else keeps the skill');
        self::assertNull($plugin->afterGetTool($registry, $legacy, 'legacy', 7), 'hidden and blocked, not only hidden');
        self::assertSame($legacy, $plugin->afterGetTool($registry, $legacy, 'legacy', 8));
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
            ['sales_data', 'mcp_demo__product_list', 'mcp_demo__product_delete', 'mcp_demo__code_runner'],
            self::names($all)
        );
    }

    #[Test]
    public function toolByNameFallsThroughToMcpSkills(): void
    {
        $registry = new ToolRegistry(new FakePermissionChecker(), [$this->magoTool]);
        $plugin = $this->plugin();

        self::assertInstanceOf(McpSkill::class, $plugin->afterGetToolByName($registry, null, 'mcp_demo__product_list'));
        self::assertInstanceOf(McpSkill::class, $plugin->afterGetToolByName($registry, null, 'mcp_demo__code_runner'));
        self::assertSame($this->magoTool, $plugin->afterGetToolByName($registry, $this->magoTool, 'sales_data'));
        self::assertNull($plugin->afterGetToolByName($registry, null, 'unknown'));
    }

    #[Test]
    public function everySkillIsEnabledWhenTheCheckerAllowsIt(): void
    {
        $checker = new FakePermissionChecker();
        $registry = new ToolRegistry($checker, [$this->magoTool]);

        $enabled = $this->plugin()->afterGetEnabledTools($registry, $registry->getEnabledTools(7), 7);

        self::assertSame(['sales_data', 'mcp_demo__product_list', 'mcp_demo__product_delete', 'mcp_demo__code_runner'], array_keys($enabled));
        self::assertContains([7, 'mcp_demo__product_list', 'read'], $checker->asked);
        self::assertContains([7, 'mcp_demo__product_delete', 'write'], $checker->asked);
        self::assertContains([7, 'mcp_demo__code_runner', 'write'], $checker->asked);
    }

    #[Test]
    public function enabledToolsRespectTheSubjectsPermissionCheck(): void
    {
        $checker = new FakePermissionChecker(false);
        $registry = new ToolRegistry($checker, [$this->magoTool]);

        $enabled = $this->plugin()->afterGetEnabledTools($registry, $registry->getEnabledTools(7), 7);

        self::assertArrayHasKey('mcp_demo__product_list', $enabled);
        self::assertArrayNotHasKey('mcp_demo__product_delete', $enabled, 'write denied by the checker');
        self::assertArrayNotHasKey('mcp_demo__code_runner', $enabled, 'write denied by the checker');
        self::assertArrayHasKey('sales_data', $enabled);
    }

    #[Test]
    public function withoutAPermissionCheckerEverySkillIsEnabled(): void
    {
        $registry = new ToolRegistry(null, [$this->magoTool]);

        $enabled = $this->plugin()->afterGetEnabledTools($registry, $registry->getEnabledTools(), null);

        self::assertSame(['sales_data', 'mcp_demo__product_list', 'mcp_demo__product_delete', 'mcp_demo__code_runner'], array_keys($enabled));
    }

    #[Test]
    public function definitionsAndGetToolGoThroughThePluginsAsTheInterceptorWould(): void
    {
        $registry = new PluggedToolRegistry(new FakePermissionChecker(false), [$this->magoTool], $this->plugin());

        $definitions = array_column($registry->getToolDefinitions(7), 'name');

        self::assertSame(['sales_data', 'mcp_demo__product_list'], $definitions);
        self::assertInstanceOf(McpSkill::class, $registry->getTool('mcp_demo__product_list', 7));
        self::assertNull($registry->getTool('mcp_demo__product_delete', 7), 'write denied');
        self::assertNull($registry->getTool('mcp_demo__code_runner', 7), 'write denied');
        self::assertInstanceOf(McpSkill::class, $registry->getToolByName('mcp_demo__code_runner'));

        $granted = new PluggedToolRegistry(new FakePermissionChecker(), [$this->magoTool], $this->plugin());
        $write = $granted->getTool('mcp_demo__code_runner', 7);
        self::assertInstanceOf(McpSkill::class, $write, 'a write grant makes a write usable');
        self::assertFalse($write->isReadOnly());
    }

    #[Test]
    public function aCheckerDenyingReadsLeavesReadSkillsOut(): void
    {
        $registry = new ToolRegistry(new FakePermissionChecker(true, false), [$this->magoTool]);

        $enabled = $this->plugin()->afterGetEnabledTools($registry, $registry->getEnabledTools(7), 7);

        self::assertArrayNotHasKey('mcp_demo__product_list', $enabled);
        self::assertArrayNotHasKey('sales_data', $enabled);
        self::assertSame(['mcp_demo__product_delete', 'mcp_demo__code_runner'], array_keys($enabled), 'the write grant still applies');
    }

    #[Test]
    public function aRoleWithoutTheWriteResourceIsOfferedOnlyReads(): void
    {
        $registry = new ToolRegistry(new FakePermissionChecker(), [$this->magoTool]);
        $plugin = $this->plugin(null, new FakeAuthorization(['MagoAssistant_Mcp::use']));

        $enabled = $plugin->afterGetEnabledTools($registry, $registry->getEnabledTools(7), 7);

        self::assertArrayHasKey('mcp_demo__product_list', $enabled);
        self::assertArrayNotHasKey('mcp_demo__product_delete', $enabled, 'the role lacks MCP Tools - Write');
        self::assertArrayNotHasKey('mcp_demo__code_runner', $enabled, 'the role lacks MCP Tools - Write');
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
        $magoNamesake = new FakeMagoTool('mcp_demo__product_list');
        $registry = new ToolRegistry(new FakePermissionChecker(), [$magoNamesake]);

        $enabled = $this->plugin()->afterGetEnabledTools($registry, $registry->getEnabledTools(7), 7);

        self::assertSame($magoNamesake, $enabled['mcp_demo__product_list']);
        self::assertSame(['mcp_demo__product_list', 'mcp_demo__product_delete', 'mcp_demo__code_runner'], array_keys($enabled));
    }

    #[Test]
    public function aFailingCatalogLeavesMagosToolsUntouched(): void
    {
        $registry = new ToolRegistry(new FakePermissionChecker(), [$this->magoTool]);
        $plugin = $this->plugin(new ThrowingSkillRegistry());
        $magoAll = $registry->getAllTools();
        $magoEnabled = $registry->getEnabledTools(7);

        self::assertSame($magoAll, $plugin->afterGetAllTools($registry, $magoAll));
        self::assertNull($plugin->afterGetToolByName($registry, null, 'mcp_demo__product_list'));
        self::assertSame($this->magoTool, $plugin->afterGetToolByName($registry, $this->magoTool, 'sales_data'));
        self::assertSame($magoEnabled, $plugin->afterGetEnabledTools($registry, $magoEnabled, 7));
        self::assertCount(3, $this->log->entries);
        self::assertStringContainsString(
            'MCP skills unavailable: {"error":"down","exception":"RuntimeException"}',
            $this->log->messages()
        );
    }

    #[Test]
    public function aConnectedAdminCanRunAnOauthSkillWhenNothingIsCachedYet(): void
    {
        // The confirm round-trip of a write: a fresh request, an empty cache, getTool() before any listing.
        $this->addOauthServer();
        $this->authenticators->credentials['remote:7'] = true;
        $plugin = $this->plugin();
        $registry = new PluggedToolRegistry(null, [$this->magoTool], $plugin);

        self::assertSame([], $this->cache->store, 'nothing cached yet');
        self::assertInstanceOf(McpSkill::class, $registry->getTool('mcp_remote__metrics_get', 7));
        self::assertNull($registry->getTool('mcp_remote__metrics_get', 8), 'an admin without a connection still cannot');
        self::assertNull($registry->getTool('mcp_remote__no_such_tool', 7));
    }

    #[Test]
    public function hotPathsDoNotQueryPerSkill(): void
    {
        $this->addOauthServer();
        $this->authenticators->credentials['remote:7'] = true;
        $plugin = $this->plugin();
        $registry = new PluggedToolRegistry(null, [$this->magoTool], $plugin);
        $plugin->afterGetEnabledTools($registry, [], 7);
        $this->servers->byNameCalls = 0;
        $this->authenticators->checks = 0;

        $plugin->afterGetEnabledTools($registry, [], 7);
        $plugin->afterGetEnabledTools($registry, [], 7);
        foreach (['mcp_demo__product_list', 'mcp_remote__metrics_get', 'sales_data'] as $name) {
            $plugin->afterGetTool($registry, $registry->getToolByName($name), $name, 7);
        }

        self::assertSame(0, $this->servers->byNameCalls, 'server rows come from the per-request list, not a query per skill');
        self::assertSame(0, $this->authenticators->checks, 'one credential check per server per admin per request, already made');
    }

    #[Test]
    public function anUnknownNonMcpNameNeverListsServers(): void
    {
        $this->addOauthServer();
        $this->authenticators->credentials['remote:7'] = true;
        $plugin = $this->plugin();
        $registry = new PluggedToolRegistry(null, [$this->magoTool], $plugin);
        $this->transport->listCalls = 0;

        self::assertNull($registry->getTool('made_up_by_the_model', 7));
        self::assertSame(0, $this->transport->listCalls, 'a name that is not an MCP skill costs no server call');
    }
}
