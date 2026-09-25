<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Catalog;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Catalog\CatalogEntry;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Discovery\DefinitionRegistry;
use MagoAssistant\Mcp\Service\Discovery\ServerDefinition;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mcp\Service\Transport\TransportResolver;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeTransport;
use MagoAssistant\Mcp\Test\Unit\Fakes\ThrowingTransport;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeServerRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolCatalogTest extends TestCase
{
    private FakeServerRepository $servers;
    private FakeTransport $transport;
    private FakeCache $cache;
    private FakeLogger $log;
    private DefinitionRegistry $definitions;

    protected function setUp(): void
    {
        $this->definitions = new DefinitionRegistry();
        $this->servers = new FakeServerRepository();
        $this->transport = new FakeTransport();
        $this->cache = new FakeCache();
        $this->log = new FakeLogger();
        $this->transport->tools['demo'] = [
            ['name' => 'product-list', 'description' => 'List products. More detail here.', 'inputSchema' => ['type' => 'object', 'properties' => []]],
            ['name' => 'product-delete', 'description' => 'Delete a product.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'code-runner', 'description' => 'Run PHP.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'customer-get', 'description' => 'Get a customer.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'stock-get', 'title' => 'Stock', 'description' => 'Named like a read.', 'inputSchema' => ['type' => 'object'],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true]],
            ['name' => 'weather', 'description' => 'Annotated read-only, unknown name.', 'inputSchema' => ['type' => 'object'],
                'annotations' => ['readOnlyHint' => true]],
        ];
    }

    /**
     * @param array<string,mixed>|null $transports keyed by transport value; null means stdio only
     */
    private function catalog(bool $enabled = true, ?array $transports = null): ToolCatalog
    {
        $config = new Config(new FakeScopeConfig([
            'mago/mcp/enabled' => $enabled ? '1' : '0',
            'mago/mcp/process_timeout' => '5',
            'mago/mcp/cache_lifetime' => '60',
        ]));

        return new ToolCatalog(
            $this->servers,
            new TransportResolver($transports ?? ['stdio' => $this->transport]),
            $this->cache,
            $config,
            new ModeClassifier(),
            new ErrorLogger($this->log, new Json()),
            $this->definitions
        );
    }

    #[Test]
    public function entriesIncludeEveryToolOfEnabledServersOnly(): void
    {
        $this->servers->add('demo', true);
        $this->servers->add('off', false);
        $this->transport->tools['off'] = [['name' => 'x-get', 'description' => '', 'inputSchema' => []]];

        $names = array_map(static fn ($e) => $e->tool, $this->catalog()->entries());

        self::assertSame(['product-list', 'product-delete', 'code-runner', 'customer-get', 'stock-get', 'weather'], $names);
    }

    #[Test]
    public function readsAndWritesComeFromNameAndAnnotations(): void
    {
        $this->servers->add('demo', true);

        $entries = $this->catalog()->entries();
        $byTool = array_combine(array_map(static fn ($e) => $e->tool, $entries), $entries);

        self::assertSame(ModeClassifier::READ, $byTool['product-list']->mode);
        self::assertSame(CatalogEntry::ORIGIN_CLASSIFIER, $byTool['product-list']->modeOrigin);
        self::assertSame(ModeClassifier::WRITE, $byTool['product-delete']->mode);
        self::assertSame(CatalogEntry::ORIGIN_CLASSIFIER, $byTool['product-delete']->modeOrigin);
        self::assertTrue($byTool['product-delete']->irreversible);
        self::assertSame(ModeClassifier::WRITE, $byTool['code-runner']->mode, 'an execution tool is a write like any other');
        self::assertSame(ModeClassifier::WRITE, $byTool['stock-get']->mode, 'readOnlyHint false turns a read name into a write');
        self::assertSame(CatalogEntry::ORIGIN_ANNOTATION, $byTool['stock-get']->modeOrigin);
        self::assertTrue($byTool['stock-get']->irreversible, 'destructiveHint marks irreversible');
        self::assertSame(ModeClassifier::WRITE, $byTool['weather']->mode, 'readOnlyHint true never turns a write into a read');
        self::assertSame(CatalogEntry::ORIGIN_CLASSIFIER, $byTool['weather']->modeOrigin);
        self::assertCount(6, $entries);
    }

    #[Test]
    public function overridesInARowAreIgnored(): void
    {
        $this->servers->add('demo', true);
        $this->servers->rows['demo']['tool_overrides'] = ['product-delete' => 'read', 'product-list' => 'disabled'];

        $entries = $this->catalog()->entries();
        $byTool = array_combine(array_map(static fn ($e) => $e->tool, $entries), $entries);

        self::assertSame(ModeClassifier::WRITE, $byTool['product-delete']->mode);
        self::assertSame(CatalogEntry::ORIGIN_CLASSIFIER, $byTool['product-delete']->modeOrigin);
        self::assertSame(ModeClassifier::READ, $byTool['product-list']->mode);
    }

    #[Test]
    public function entriesForServerFlagsPersonalData(): void
    {
        $this->servers->add('demo', true);

        $all = $this->catalog()->entriesForServer('demo');

        self::assertCount(6, $all);
        $customer = $all[3];
        self::assertSame(ModeClassifier::READ, $customer->mode);
        self::assertSame(CatalogEntry::ORIGIN_CLASSIFIER, $customer->modeOrigin);
        self::assertTrue($customer->personalData);

        $byTool = array_combine(array_map(static fn ($e) => $e->tool, $all), $all);
        self::assertSame(ModeClassifier::WRITE, $byTool['code-runner']->mode);
        self::assertSame(CatalogEntry::ORIGIN_CLASSIFIER, $byTool['code-runner']->modeOrigin);
        self::assertFalse($byTool['product-delete']->personalData);
    }

    #[Test]
    public function emptyPropertiesStayAnObject(): void
    {
        $this->servers->add('demo', true);

        $schema = $this->catalog()->entriesForServer('demo')[0]->inputSchema;

        self::assertInstanceOf(\stdClass::class, $schema['properties']);
        self::assertSame('{"type":"object","properties":{}}', json_encode($schema));
    }

    #[Test]
    public function nestedEmptyObjectsStayObjectsAndListsStayLists(): void
    {
        $this->transport->tools['demo'] = [[
            'name' => 'product-list',
            'description' => 'List products.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'tags' => ['type' => 'array', 'items' => []],
                    'meta' => ['type' => 'object', 'properties' => []],
                ],
                'required' => [],
            ],
        ]];
        $this->servers->add('demo', true);

        $json = (string)json_encode($this->catalog()->entriesForServer('demo')[0]->inputSchema);

        self::assertStringContainsString('"tags":{"type":"array","items":{}}', $json);
        self::assertStringContainsString('"meta":{"type":"object","properties":{}}', $json);
        self::assertStringContainsString('"required":[]', $json);
    }

    #[Test]
    public function cachesToolListAndRefreshClearsIt(): void
    {
        $this->servers->add('demo', true);
        $catalog = $this->catalog();

        $catalog->entries();
        $catalog->entries();
        self::assertSame(1, $this->transport->listCalls);

        $catalog->refresh('demo');
        $catalog->entries();
        self::assertSame(2, $this->transport->listCalls);
    }

    #[Test]
    public function cacheIdsAreSafeForMagentoCache(): void
    {
        $this->servers->add('demo_2', true);
        $this->transport->tools['demo_2'] = $this->transport->tools['demo'];

        $catalog = $this->catalog();
        $catalog->entries();
        $catalog->refresh('demo_2');

        self::assertSame(1, $this->cache->saves, 'FakeCache would have thrown on an invalid id');
    }

    #[Test]
    public function aServerIsFoundByItsHyphenOrUnderscoreName(): void
    {
        $this->servers->add('my-server', true);
        $this->transport->tools['my_server'] = $this->transport->tools['demo'];

        self::assertSame('my_server', $this->servers->rows['my_server']['name'], 'stored under the normalised name');
        self::assertNotNull($this->catalog()->serverConfig('my-server'));
        self::assertNotNull($this->catalog()->serverConfig('my_server'));
        self::assertCount(6, $this->catalog()->entriesForServer('my-server'));
    }

    #[Test]
    public function skipsDisabledServersAndIsolatesFailures(): void
    {
        $this->servers->add('demo', true);
        $this->servers->add('broken', true);
        $this->servers->add('off', false);
        $this->transport->tools['off'] = [['name' => 'x-get', 'description' => '', 'inputSchema' => []]];
        $this->transport->failures['broken'] = 'exited early';

        $entries = $this->catalog()->entries();

        self::assertSame(['demo'], array_unique(array_map(static fn ($e) => $e->server, $entries)));
        self::assertSame('exited early', $this->servers->rows['broken']['last_error']);
        self::assertNull($this->servers->rows['demo']['last_error']);
        self::assertStringContainsString('exited early', $this->log->messages());
        self::assertCount(6, array_filter($entries, static fn ($e) => $e->server === 'demo'), 'every tool counts');
        self::assertCount(0, array_filter($entries, static fn ($e) => $e->server === 'broken'));
    }

    #[Test]
    public function aFailedToolListIsNotRetriedForFiveMinutes(): void
    {
        $this->servers->add('broken', true);
        $this->transport->failures['broken'] = 'exited early';

        $this->catalog()->entries();
        $this->catalog()->entries();

        self::assertSame(1, $this->transport->listCalls, 'the failure is cached briefly');
        self::assertArrayHasKey('mago_mcp_tools_broken', $this->cache->store);
        self::assertSame('exited early', $this->servers->rows['broken']['last_error']);
        self::assertSame(300, $this->cache->lifetimes['mago_mcp_tools_broken'], 'a down server costs one attempt per five minutes');
    }

    #[Test]
    public function anUnknownTransportIsAFailureOfThatServerOnly(): void
    {
        $this->servers->add('demo', true);
        $this->servers->add('remote', true, 'manual', ['transport' => 'http', 'command' => []]);

        $entries = $this->catalog()->entries();

        self::assertSame(['demo'], array_unique(array_map(static fn ($e) => $e->server, $entries)));
        self::assertStringContainsString(
            'uses transport "http", which is not available',
            (string)$this->servers->rows['remote']['last_error']
        );
        self::assertArrayHasKey('mago_mcp_tools_remote', $this->cache->store, 'the failure is cached like any other');
        self::assertSame(1, $this->transport->listCalls, 'the stdio transport was asked once, for demo');
    }

    #[Test]
    public function aDefinitionsPerToolOverrideReachesTheEntry(): void
    {
        $this->servers->add('demo', true, 'module', ['output_public' => true]);
        $this->definitions = new DefinitionRegistry([new ServerDefinition('demo', fieldClassificationOverrides: [
            'customer-get' => ['*' => ['public'], 'email' => ['strip']],
        ])]);

        $entries = [];
        foreach ($this->catalog()->entries() as $entry) {
            $entries[$entry->tool] = $entry;
        }

        self::assertSame(['*' => ['public'], 'email' => ['strip']], $entries['customer-get']->fieldClassification);
        self::assertNull($entries['product-list']->fieldClassification, 'no override: the server-wide rule applies');
    }

    #[Test]
    public function anyThrowableFromATransportIsThatServersFailureOnly(): void
    {
        $this->servers->add('demo', true);
        $this->servers->add('remote', true, 'manual', ['transport' => 'http', 'command' => []]);
        $catalog = $this->catalog(true, ['stdio' => $this->transport, 'http' => new ThrowingTransport()]);

        $entries = $catalog->entries();

        self::assertSame(['demo'], array_unique(array_map(static fn ($e) => $e->server, $entries)));
        self::assertStringContainsString('malformed response', (string)$this->servers->rows['remote']['last_error']);
        self::assertArrayHasKey('mago_mcp_tools_remote', $this->cache->store);
    }

    #[Test]
    public function allowedToolsFiltersAndIgnoresUnknownNames(): void
    {
        $this->servers->add('demo', true, 'composer', ['allowed_tools' => ['product-list', 'no-such-tool']]);

        $tools = array_map(static fn ($e) => $e->tool, $this->catalog()->entries());

        self::assertSame(['product-list'], $tools);
    }

    #[Test]
    public function serverConfigResolvesEnabledServersOnly(): void
    {
        $this->servers->add('demo', true);
        $this->servers->add('off', false);
        $catalog = $this->catalog();

        self::assertSame(['php', 'demo'], $catalog->serverConfig('demo')?->command);
        self::assertNull($catalog->serverConfig('off'));
        self::assertNull($catalog->serverConfig('nope'));
    }

    #[Test]
    public function keepsServerInstructionsForEnabledServers(): void
    {
        $this->servers->add('demo', true);
        $this->transport->instructions['demo'] = 'Prefer SKUs over ids.';

        self::assertSame(['demo' => 'Prefer SKUs over ids.'], $this->catalog()->serverInstructions());
    }

    #[Test]
    public function moduleDisabledYieldsNothing(): void
    {
        $this->servers->add('demo', true);

        self::assertSame([], $this->catalog(false)->entries());
    }

    #[Test]
    public function aReadToolIsNeverIrreversibleEvenWithAnIrreversibleWordInItsName(): void
    {
        $this->transport->tools['demo'][] = ['name' => 'creditmemo-list', 'description' => '', 'inputSchema' => ['type' => 'object']];
        $this->servers->add('demo', true);

        $all = $this->catalog()->entriesForServer('demo');
        $byTool = array_combine(array_map(static fn ($e) => $e->tool, $all), $all);

        self::assertSame(ModeClassifier::READ, $byTool['creditmemo-list']->mode, '"creditmemo" is on IRREVERSIBLE_WORDS but the tool still reads');
        self::assertFalse($byTool['creditmemo-list']->irreversible, 'a read is never irreversible');
    }

    #[Test]
    public function readOnlyHintFalseAloneMakesAWriteThatIsNotIrreversible(): void
    {
        $this->transport->tools['demo'][] = ['name' => 'price-get', 'description' => 'Named like a read.',
            'inputSchema' => ['type' => 'object'], 'annotations' => ['readOnlyHint' => false]];
        $this->servers->add('demo', true);

        $all = $this->catalog()->entriesForServer('demo');
        $byTool = array_combine(array_map(static fn ($e) => $e->tool, $all), $all);

        self::assertSame(ModeClassifier::WRITE, $byTool['price-get']->mode);
        self::assertSame(CatalogEntry::ORIGIN_ANNOTATION, $byTool['price-get']->modeOrigin);
        self::assertFalse($byTool['price-get']->irreversible);
    }

    #[Test]
    public function destructiveHintTightensAReadNameToWrite(): void
    {
        $this->transport->tools['demo'] = [
            ['name' => 'log-get', 'description' => '', 'inputSchema' => ['type' => 'object'],
                'annotations' => ['destructiveHint' => true]],
            ['name' => 'audit-get', 'description' => '', 'inputSchema' => ['type' => 'object'],
                'annotations' => ['destructiveHint' => false]],
        ];
        $this->servers->add('demo', true);

        [$tightened, $plain] = $this->catalog()->entriesForServer('demo');

        self::assertSame(ModeClassifier::WRITE, $tightened->mode);
        self::assertSame(CatalogEntry::ORIGIN_ANNOTATION, $tightened->modeOrigin);
        self::assertTrue($tightened->irreversible);
        self::assertSame(ModeClassifier::READ, $plain->mode);
        self::assertSame(CatalogEntry::ORIGIN_CLASSIFIER, $plain->modeOrigin);
        self::assertFalse($plain->irreversible);
    }
}
