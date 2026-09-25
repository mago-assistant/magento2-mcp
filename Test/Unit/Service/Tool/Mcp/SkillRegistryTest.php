<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Tool\Mcp;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as MagoConfig;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Tool\Mcp\Executor;
use MagoAssistant\Mcp\Service\Tool\Mcp\McpSkill;
use MagoAssistant\Mcp\Service\Tool\Mcp\SkillRegistry;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mcp\Service\Transport\TransportResolver;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeTransport;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeServerRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SkillRegistryTest extends TestCase
{
    private FakeServerRepository $servers;
    private FakeTransport $transport;
    private FakeLogger $log;

    protected function setUp(): void
    {
        $this->servers = new FakeServerRepository();
        $this->transport = new FakeTransport();
        $this->log = new FakeLogger();
        $this->transport->tools['demo'] = [
            ['name' => 'product-list', 'description' => 'List products.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'product-delete', 'description' => 'Delete a product.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'code-runner', 'description' => 'Run PHP.', 'inputSchema' => ['type' => 'object']],
        ];
        $this->transport->tools['acme'] = [
            ['name' => 'order-list', 'description' => 'List orders.', 'inputSchema' => ['type' => 'object']],
        ];
        $this->servers->add('demo', true);
        $this->servers->add('acme', false);
    }

    private function registry(): SkillRegistry
    {
        $config = new Config(new FakeScopeConfig([
            'mago/mcp/enabled' => '1',
            'mago/mcp/process_timeout' => '5',
            'mago/mcp/cache_lifetime' => '60',
            'mago/mcp/max_result_chars' => '16000',
        ]));
        $json = new Json();
        $errorLogger = new ErrorLogger($this->log, $json);
        $catalog = new ToolCatalog(
            $this->servers,
            new TransportResolver(['stdio' => $this->transport]),
            new FakeCache(),
            $config,
            new ModeClassifier(),
            $errorLogger
        );
        $executor = new Executor(
            $catalog,
            new TransportResolver(['stdio' => $this->transport]),
            $config,
            new DebugLogger($this->log, $json),
            $errorLogger,
            $this->createStub(MagoConfig::class)
        );

        return new SkillRegistry($catalog, $executor, $errorLogger, new ModeClassifier());
    }

    /**
     * @param McpSkill[] $skills
     * @return string[]
     */
    private static function names(array $skills): array
    {
        return array_map(static fn (McpSkill $s): string => $s->getName(), $skills);
    }

    #[Test]
    public function everyToolOfEveryEnabledServerIsASkill(): void
    {
        $names = self::names($this->registry()->all());

        self::assertSame(['demo__product-list', 'demo__product-delete', 'demo__code-runner'], $names);
        self::assertNotContains('acme__order-list', $names, 'a disabled server has no skills');
    }

    #[Test]
    public function byNameFindsMcpSkillsAndNothingElse(): void
    {
        $registry = $this->registry();

        self::assertInstanceOf(McpSkill::class, $registry->byName('demo__code-runner'));
        self::assertInstanceOf(McpSkill::class, $registry->byName('demo__product-list'));
        self::assertNull($registry->byName('sales_data'));
        self::assertTrue($registry->isMcpSkill('demo__product-delete'));
        self::assertFalse($registry->isMcpSkill('sales_data'));
    }

    #[Test]
    public function theExecutionFlagComesFromTheClassifier(): void
    {
        $registry = $this->registry();

        self::assertStringEndsWith(' [runs code, SQL or commands]', (string)$registry->byName('demo__code-runner')?->getDescription());
        self::assertStringNotContainsString('[runs code', (string)$registry->byName('demo__product-delete')?->getDescription());
    }

    #[Test]
    public function skillsAreBuiltOncePerRequest(): void
    {
        $registry = $this->registry();

        $first = $registry->all();
        $registry->all();
        $registry->byName('demo__product-list');
        $registry->isMcpSkill('nope');

        self::assertSame(1, $this->transport->listCalls, 'one listing for the one enabled server');
        self::assertSame($first[0], $registry->all()[0], 'the same skill objects are returned');
    }

    #[Test]
    public function serverInstructionsReachTheSkills(): void
    {
        $this->transport->instructions['demo'] = 'Use SKUs, not ids.';

        $skill = $this->registry()->byName('demo__product-list');

        self::assertStringContainsString('Use SKUs, not ids.', (string)$skill?->getInstructions());
    }

    #[Test]
    public function aNameCollisionKeepsTheFirstSkillAndLogsIt(): void
    {
        $this->transport->tools['demo'] = [
            ['name' => 'a.b', 'description' => 'First.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'a:b', 'description' => 'Second.', 'inputSchema' => ['type' => 'object']],
        ];
        $registry = $this->registry();

        $skills = $registry->all();
        $registry->all();

        self::assertSame(['demo__a-b'], self::names($skills));
        self::assertSame('a.b', $registry->byName('demo__a-b')?->entry()->tool);
        $collisions = array_filter(
            $this->log->entries,
            static fn (array $e): bool => str_contains($e[1], 'MCP skill name collision')
        );
        self::assertCount(1, $collisions, 'logged once, not per call');
        $message = (string)array_values($collisions)[0][1];
        self::assertStringContainsString('"name":"demo__a-b"', $message);
        self::assertStringContainsString('"server":"demo"', $message);
        self::assertStringContainsString('"tool":"a:b"', $message);
    }

    #[Test]
    public function aSkillWhoseNameProvidersRejectIsLeftOutAndLogged(): void
    {
        $long = str_repeat('x', 70);
        $this->transport->tools['demo'][] = [
            'name' => $long,
            'description' => 'Too long.',
            'inputSchema' => ['type' => 'object'],
        ];
        $registry = $this->registry();

        $names = self::names($registry->all());
        $registry->all();

        self::assertSame(['demo__product-list', 'demo__product-delete', 'demo__code-runner'], $names);
        self::assertNull($registry->byName('demo__' . $long));
        $unusable = array_filter(
            $this->log->entries,
            static fn (array $e): bool => str_contains($e[1], 'MCP skill name unusable')
        );
        self::assertCount(1, $unusable, 'logged once per request');
        $message = (string)array_values($unusable)[0][1];
        self::assertStringContainsString('"server":"demo"', $message);
        self::assertStringContainsString('"tool":"' . $long . '"', $message);
    }
}
