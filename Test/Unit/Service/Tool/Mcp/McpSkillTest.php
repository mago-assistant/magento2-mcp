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
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Catalog\CatalogEntry;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Discovery\DefinitionRegistry;
use MagoAssistant\Mcp\Service\Tool\Mcp\Executor;
use MagoAssistant\Mcp\Service\Tool\Mcp\McpSkill;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mcp\Service\Transport\TransportResolver;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeTransport;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakePermissionChecker;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeServerRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class McpSkillTest extends TestCase
{
    private FakeServerRepository $servers;
    private FakeTransport $transport;
    private FakeLogger $log;
    private int $maxChars = 16000;

    protected function setUp(): void
    {
        $this->servers = new FakeServerRepository();
        $this->transport = new FakeTransport();
        $this->log = new FakeLogger();
        $this->transport->tools['demo'] = [
            ['name' => 'product-list', 'description' => 'List products with filters. Supports paging.',
                'inputSchema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer'], 'sku' => ['type' => 'string']], 'required' => ['limit']]],
            ['name' => 'product-delete', 'description' => 'Delete a product by SKU.',
                'inputSchema' => ['type' => 'object', 'properties' => ['sku' => ['type' => 'string']], 'required' => ['sku']]],
            ['name' => 'code-runner', 'description' => 'Run PHP.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'customer-get', 'description' => 'Get a customer.', 'inputSchema' => ['type' => 'object']],
        ];
        $this->servers->add('demo', true);
    }

    private function skill(string $tool, string $serverInstructions = ''): McpSkill
    {
        $config = new Config(new FakeScopeConfig([
            'mago/mcp/enabled' => '1',
            'mago/mcp/process_timeout' => '5',
            'mago/mcp/cache_lifetime' => '60',
            'mago/mcp/max_result_chars' => (string)$this->maxChars,
        ]));
        $json = new Json();
        $magoConfig = $this->createStub(MagoConfig::class);
        $magoConfig->method('isDebugEnabled')->willReturn(true);
        $catalog = new ToolCatalog(
            $this->servers,
            new TransportResolver(['stdio' => $this->transport]),
            new FakeCache(),
            $config,
            new ModeClassifier(),
            new ErrorLogger($this->log, $json),
            new DefinitionRegistry()
        );
        $executor = new Executor(
            $catalog,
            new TransportResolver(['stdio' => $this->transport]),
            $config,
            new DebugLogger($this->log, $json),
            new ErrorLogger($this->log, $json),
            $magoConfig
        );
        $classifier = new ModeClassifier();
        foreach ($catalog->entries() as $entry) {
            if ($entry->tool === $tool) {
                return new McpSkill($entry, $executor, $serverInstructions, $classifier->isExecutionSurface($tool));
            }
        }
        self::fail('fixture has no tool ' . $tool);
    }

    #[Test]
    public function nameDescriptionAndSchemaComeFromTheEntry(): void
    {
        $skill = $this->skill('product-list');

        self::assertSame('mcp_demo__product_list', $skill->getName());
        self::assertStringStartsWith('demo: product-list — List products with filters.', $skill->getDescription());
        self::assertStringNotContainsString('Supports paging', $skill->getDescription());
        self::assertSame(
            ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer'], 'sku' => ['type' => 'string']], 'required' => ['limit']],
            $skill->getParameterSchema()
        );
        self::assertTrue($skill->isReadOnly());
        self::assertTrue($skill->isReadOnlyAction([]));
        self::assertSame('MagoAssistant_Mcp::use', $skill->getMagentoAcl());
        self::assertSame('product-list', $skill->entry()->tool);
    }

    #[Test]
    public function aSchemaWithoutPropertiesHasNoPropertiesKey(): void
    {
        $this->transport->tools['demo'][] = ['name' => 'cache-flush', 'description' => 'Flush.',
            'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []]];

        $bare = $this->skill('code-runner')->getParameterSchema();
        $emptied = $this->skill('cache-flush')->getParameterSchema();

        self::assertSame(['type' => 'object'], $bare);
        self::assertSame(['type' => 'object', 'required' => []], $emptied, 'an empty properties object is dropped');
        self::assertStringNotContainsString('[]', (string)json_encode($bare));
        self::assertStringNotContainsString('"properties"', (string)json_encode($emptied));
    }

    #[Test]
    public function propertyEntriesAreArraysAndDeeperEmptyObjectsStayObjects(): void
    {
        $this->transport->tools['demo'][] = [
            'name' => 'tag-list',
            'description' => 'List tags.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'tags' => ['type' => 'array', 'items' => []],
                    'meta' => ['type' => 'object', 'properties' => []],
                    'anything' => [],
                    'action' => [],
                ],
                'required' => [],
            ],
        ];

        $skill = $this->skill('tag-list');
        $schema = $skill->getParameterSchema();

        self::assertIsArray($schema['properties']['meta']);
        self::assertEquals(['type' => 'object', 'properties' => new \stdClass()], $schema['properties']['meta']);
        self::assertInstanceOf(\stdClass::class, $schema['properties']['meta']['properties']);
        $json = (string)json_encode($skill->getParameterSchema());
        self::assertStringContainsString('"items":{}', $json);
        self::assertStringContainsString('"anything":{}', $json, 'an empty property schema reaches the provider as {}');
        self::assertSame([], $schema['properties']['action'], 'Mago indexes properties.action as an array');
    }

    #[Test]
    public function aWriteSkillWithoutArgumentsSurvivesMagosRegistry(): void
    {
        $skill = $this->skill('code-runner');
        // A permission checker is what makes Mago read ['properties']['action']['enum'] from the schema;
        // without one, isToolAvailable() and hasWriteAccess() return before looking at it.
        $checker = new FakePermissionChecker();
        $registry = new ToolRegistry($checker, [$skill]);

        self::assertFalse($skill->isReadOnly());
        self::assertContains($skill, $registry->getEnabledTools(1));
        self::assertSame([1, 'mcp_demo__code_runner', 'write'], $checker->asked[0], 'no read action found, so write is asked');
        self::assertSame(['type' => 'object'], $registry->getToolDefinitions(1)[0]['parameters']);

        $readOnlyUser = new ToolRegistry(new FakePermissionChecker(false), [$skill]);
        self::assertSame(['type' => 'object'], $readOnlyUser->getToolDefinition($skill, 1)['parameters']);
    }

    #[Test]
    public function magosReservedKeysNeverReachTheServer(): void
    {
        $skill = $this->skill('product-list');

        self::assertNull($skill->findRefusal(['limit' => 1, '_admin_user_id' => 7]));
        $skill->execute(['limit' => 1, '_admin_user_id' => 7]);

        self::assertSame(['demo', 'product-list', ['limit' => 1]], $this->transport->calls[0]);
        self::assertSame([7], $this->transport->adminUserIds, 'the admin id reaches the transport, not the server arguments');
        self::assertStringContainsString('"argument_keys":["limit"]', $this->log->messages());
        self::assertStringNotContainsString('_admin_user_id', $this->log->messages());
    }

    #[Test]
    public function namesAreProviderSafe(): void
    {
        self::assertSame('mcp_acme__sys_info', McpSkill::nameFor('acme', 'sys:info'));
        self::assertSame('mcp_acme__a_b_c', McpSkill::nameFor('acme', 'a.b/C'));
        self::assertSame('mcp_demo__product_list', McpSkill::nameFor('demo', 'product-list'));
        self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', McpSkill::nameFor('Acme-1', 'Sys:Info'));
    }

    #[Test]
    public function namesAreCutAndHashedOnlyAbove64Characters(): void
    {
        $tool64 = str_repeat('a', 64 - strlen('mcp_demo__'));
        self::assertSame(64, strlen(McpSkill::nameFor('demo', $tool64)));
        self::assertSame('mcp_demo__' . $tool64, McpSkill::nameFor('demo', $tool64), 'exactly 64 is kept as is');

        $long = McpSkill::nameFor('demo', $tool64 . 'b');
        self::assertSame(64, strlen($long));
        self::assertStringStartsWith('mcp_demo__' . substr($tool64, 0, 45) . '_', $long);
        self::assertMatchesRegularExpression('/_[0-9a-f]{8}$/', $long);
        self::assertNotSame($long, McpSkill::nameFor('demo', $tool64 . 'c'), 'the hash tells long names apart');
    }

    #[Test]
    public function descriptionUsesTheServersLabelWhenItHasOne(): void
    {
        $this->servers->rows['demo']['label'] = 'Demo Shop';

        self::assertStringStartsWith('Demo Shop: product-list — ', $this->skill('product-list')->getDescription());
    }

    #[Test]
    public function descriptionCarriesTheInformationFlags(): void
    {
        self::assertSame('demo: customer-get — Get a customer. [personal data]', $this->skill('customer-get')->getDescription());
        self::assertSame('demo: code-runner — Run PHP. [runs code, SQL or commands]', $this->skill('code-runner')->getDescription());
        self::assertSame('demo: product-list — List products with filters.', $this->skill('product-list')->getDescription());
    }

    #[Test]
    public function aWriteSkillRunsAfterValidation(): void
    {
        $this->skill('code-runner')->execute([]);

        self::assertSame(['demo', 'code-runner', []], $this->transport->calls[0], 'no hidden state stops a write');
    }

    #[Test]
    public function refusesInvalidWritesBeforeConfirmation(): void
    {
        $skill = $this->skill('product-delete');

        self::assertFalse($skill->isReadOnly());
        self::assertSame('MagoAssistant_Mcp::use_write', $skill->getMagentoAcl());
        self::assertNull($skill->findRefusal(['sku' => 'A']));
        self::assertStringContainsString('"sku" must be string', $skill->findRefusal(['sku' => 3])['error']);
        $missing = $skill->findRefusal([]);
        self::assertStringContainsString('Invalid arguments for mcp_demo__product_delete', $missing['error']);
        self::assertStringContainsString('missing required argument "sku"', $missing['error']);
        self::assertStringContainsString('"required":["sku"]', $missing['error']);
        self::assertSame([], $this->transport->calls, 'a refusal never reaches the server');
    }

    #[Test]
    public function coercesNumericStringsAndBooleans(): void
    {
        $this->skill('product-list')->execute(['limit' => '3', 'sku' => '42']);

        self::assertSame(['demo', 'product-list', ['limit' => 3, 'sku' => '42']], $this->transport->calls[0], 'integer coerced, string left alone');
        self::assertStringContainsString('"limit" must be integer', $this->skill('product-list')->execute(['limit' => 'five'])['error']);
    }

    #[Test]
    public function executesAndShapesTheResult(): void
    {
        $this->transport->nextResult = ['content' => [['type' => 'text', 'text' => 'one'], ['type' => 'text', 'text' => 'two']], 'isError' => false];

        $result = $this->skill('product-list')->execute(['limit' => 5, 'sku' => 'SECRET-SKU']);

        self::assertSame(['demo', 'product-list', ['limit' => 5, 'sku' => 'SECRET-SKU']], $this->transport->calls[0]);
        self::assertSame(['server' => 'demo', 'action' => 'product-list', 'result' => "one\n\ntwo"], $result);
        $logged = $this->log->messages();
        self::assertStringContainsString('MCP call', $logged);
        self::assertStringContainsString('"skill":"mcp_demo__product_list"', $logged);
        self::assertStringContainsString('argument_keys', $logged);
        self::assertStringNotContainsString('SECRET-SKU', $logged, 'argument values never reach the log');
    }

    #[Test]
    public function anErrorResultIsReturnedInMagosErrorShape(): void
    {
        $this->transport->nextResult = ['content' => [['type' => 'text', 'text' => 'boom']], 'isError' => true];

        self::assertSame(['error' => 'boom'], $this->skill('product-list')->execute(['limit' => 1]));
    }

    #[Test]
    public function longTextIsTruncated(): void
    {
        $this->maxChars = 10;
        $this->transport->nextResult = ['content' => [['type' => 'text', 'text' => str_repeat('x', 25)]], 'isError' => false];

        self::assertSame(
            str_repeat('x', 10) . ' [truncated: 15 more characters]',
            $this->skill('product-list')->execute(['limit' => 1])['result']
        );
    }

    #[Test]
    public function aNonPublicServersResultIsOnePublicStringForTheScrub(): void
    {
        self::assertSame(
            ['server' => [PiiClass::PUBLIC], 'action' => [PiiClass::PUBLIC], 'result' => [PiiClass::PUBLIC]],
            $this->skill('product-list')->getFieldClassification()
        );
    }

    #[Test]
    public function aPublicServersResultIsWildcardPublic(): void
    {
        $this->servers->rows['demo']['output_public'] = true;

        self::assertSame(
            ['server' => [PiiClass::PUBLIC], 'action' => [PiiClass::PUBLIC], PiiClass::ANY => [PiiClass::PUBLIC]],
            $this->skill('product-list')->getFieldClassification()
        );
    }

    #[Test]
    public function aToolOverrideReplacesTheWildcardOnAPublicServer(): void
    {
        $entry = new CatalogEntry(
            'demo',
            'who-am-i',
            'Who.',
            ['type' => 'object'],
            ModeClassifier::READ,
            CatalogEntry::ORIGIN_CLASSIFIER,
            false,
            false,
            '',
            true,
            [PiiClass::ANY => [PiiClass::PUBLIC], 'name' => [PiiClass::STRIP], 'email' => [PiiClass::STRIP]]
        );
        $skill = new McpSkill($entry, $this->createStub(Executor::class));

        self::assertSame(
            [
                'server' => [PiiClass::PUBLIC],
                'action' => [PiiClass::PUBLIC],
                PiiClass::ANY => [PiiClass::PUBLIC],
                'name' => [PiiClass::STRIP],
                'email' => [PiiClass::STRIP],
            ],
            $skill->getFieldClassification()
        );
    }

    #[Test]
    public function resultWithoutTextBlocksSaysSo(): void
    {
        $this->transport->nextResult = ['content' => [['type' => 'image', 'data' => 'AAAA', 'mimeType' => 'image/png']], 'isError' => false];

        $result = $this->skill('product-list')->execute(['limit' => 1]);

        self::assertSame('(no text content: 1 image block)', $result['result']);
    }

    #[Test]
    public function writesAreIrreversibleWhenTheNameSaysSo(): void
    {
        $delete = $this->skill('product-delete');

        self::assertTrue($delete->isIrreversibleAction([]));
        self::assertCount(2, $delete->getImpacts(['sku' => 'X'], 1));
        self::assertFalse($this->skill('product-list')->isIrreversibleAction([]));
    }

    #[Test]
    public function instructionsCarryServerNotesAndTheSchema(): void
    {
        $instructions = $this->skill('product-list', 'Prefer SKUs over ids.')->getInstructions();

        self::assertStringContainsString("## Server demo\nPrefer SKUs over ids.", $instructions);
        self::assertStringContainsString('## mcp_demo__product_list', $instructions);
        self::assertStringContainsString('Input schema:', $instructions);
        self::assertStringContainsString('"required":["limit"]', $instructions);
        self::assertStringNotContainsString('## Server', $this->skill('product-list')->getInstructions());
    }

    #[Test]
    public function fieldClassificationCoversEveryResultKey(): void
    {
        $skill = $this->skill('product-list');
        $classification = $skill->getFieldClassification();

        self::assertSame(array_keys($skill->execute(['limit' => 1])), array_keys($classification));
        foreach ($classification as $classes) {
            self::assertSame([PiiClass::PUBLIC], $classes);
        }
    }
}
