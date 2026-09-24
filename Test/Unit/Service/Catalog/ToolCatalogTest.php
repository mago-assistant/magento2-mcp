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
use MagoAssistant\Mcp\Test\Unit\Fakes\BricklayerProfile;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeMcpClient;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeServerRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolCatalogTest extends TestCase
{
    private FakeServerRepository $servers;
    private FakeMcpClient $client;
    private FakeCache $cache;
    private FakeLogger $log;

    protected function setUp(): void
    {
        $this->servers = new FakeServerRepository();
        $this->client = new FakeMcpClient();
        $this->cache = new FakeCache();
        $this->log = new FakeLogger();
        $this->client->tools['bricklayer'] = [
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

    private function catalog(bool $enabled = true): ToolCatalog
    {
        $config = new Config(new FakeScopeConfig([
            'mago/mcp/enabled' => $enabled ? '1' : '0',
            'mago/mcp/process_timeout' => '5',
            'mago/mcp/cache_lifetime' => '60',
        ]));

        return new ToolCatalog(
            $this->servers,
            $this->client,
            $this->cache,
            $config,
            new ModeClassifier(),
            BricklayerProfile::defaults(),
            new ErrorLogger($this->log, new Json())
        );
    }

    #[Test]
    public function appliesOverrideThenDefaultThenClassifierThenAnnotations(): void
    {
        $this->servers->add('bricklayer', true, ['code-runner' => 'write', 'product-list' => 'disabled']);

        $entries = $this->catalog()->entries();
        $byTool = array_combine(array_map(static fn ($e) => $e->tool, $entries), $entries);

        self::assertSame(['product-delete', 'code-runner', 'customer-get', 'stock-get', 'weather'], array_keys($byTool));
        self::assertSame(CatalogEntry::ORIGIN_OVERRIDE, $byTool['code-runner']->modeOrigin);
        self::assertSame(CatalogEntry::ORIGIN_CLASSIFIER, $byTool['product-delete']->modeOrigin);
        self::assertTrue($byTool['product-delete']->irreversible);
        self::assertSame('bricklayer:product-delete', $byTool['product-delete']->action());
        self::assertSame(ModeClassifier::WRITE, $byTool['stock-get']->mode, 'readOnlyHint false overrides a read name');
        self::assertSame(CatalogEntry::ORIGIN_ANNOTATION, $byTool['stock-get']->modeOrigin);
        self::assertTrue($byTool['stock-get']->irreversible, 'destructiveHint marks irreversible');
        self::assertSame('Stock', $byTool['stock-get']->title);
        self::assertSame(ModeClassifier::WRITE, $byTool['weather']->mode, 'readOnlyHint true never upgrades to read');
    }

    #[Test]
    public function entriesForServerIncludesDisabledAndFlagsPersonalData(): void
    {
        $this->servers->add('bricklayer', true);

        $all = $this->catalog()->entriesForServer('bricklayer');

        self::assertCount(6, $all);
        $customer = $all[3];
        self::assertSame(ModeClassifier::READ, $customer->mode);
        self::assertSame(CatalogEntry::ORIGIN_CLASSIFIER, $customer->modeOrigin);
        self::assertTrue($customer->personalData);
    }

    #[Test]
    public function emptyPropertiesStayAnObject(): void
    {
        $this->servers->add('bricklayer', true);

        $schema = $this->catalog()->entriesForServer('bricklayer')[0]->inputSchema;

        self::assertInstanceOf(\stdClass::class, $schema['properties']);
        self::assertSame('{"type":"object","properties":{}}', json_encode($schema));
    }

    #[Test]
    public function cachesToolListAndRefreshClearsIt(): void
    {
        $this->servers->add('bricklayer', true);
        $catalog = $this->catalog();

        $catalog->entries();
        $catalog->entries();
        self::assertSame(1, $this->client->listCalls);

        $catalog->refresh('bricklayer');
        $catalog->entries();
        self::assertSame(2, $this->client->listCalls);
    }

    #[Test]
    public function cacheIdsAreSafeForMagentoCache(): void
    {
        $this->servers->add('magento-bricklayer', true);
        $this->client->tools['magento-bricklayer'] = $this->client->tools['bricklayer'];

        $catalog = $this->catalog();
        $catalog->entries();
        $catalog->refresh('magento-bricklayer');

        self::assertSame(1, $this->cache->saves, 'FakeCache would have thrown on an invalid id');
    }

    #[Test]
    public function skipsDisabledServersAndIsolatesFailures(): void
    {
        $this->servers->add('bricklayer', true);
        $this->servers->add('broken', true);
        $this->servers->add('off', false);
        $this->client->tools['off'] = [['name' => 'x-get', 'description' => '', 'inputSchema' => []]];
        $this->client->failures['broken'] = 'exited early';

        $entries = $this->catalog()->entries();

        self::assertSame(['bricklayer'], array_unique(array_map(static fn ($e) => $e->server, $entries)));
        self::assertSame('exited early', $this->servers->rows['broken']['last_error']);
        self::assertNull($this->servers->rows['bricklayer']['last_error']);
        self::assertStringContainsString('exited early', $this->log->messages());
        self::assertSame(['bricklayer' => 5, 'broken' => 0], $this->catalog()->serverCounts());
    }

    #[Test]
    public function aFailedToolListIsNotRetriedForAMinute(): void
    {
        $this->servers->add('broken', true);
        $this->client->failures['broken'] = 'exited early';

        $this->catalog()->entries();
        $this->catalog()->entries();

        self::assertSame(1, $this->client->listCalls, 'the failure is cached briefly');
        self::assertArrayHasKey('mago_mcp_tools_broken', $this->cache->store);
        self::assertSame('exited early', $this->servers->rows['broken']['last_error']);
    }

    #[Test]
    public function findOnlyReturnsCallableEntries(): void
    {
        $this->servers->add('bricklayer', true);
        $catalog = $this->catalog();

        self::assertNotNull($catalog->find('bricklayer:product-list'));
        self::assertNull($catalog->find('bricklayer:code-runner'));
        self::assertNull($catalog->find('nope:product-list'));
        self::assertNull($catalog->find('garbage'));
        self::assertSame(['php', 'bricklayer'], $catalog->serverConfig('bricklayer')?->command);
    }

    #[Test]
    public function keepsServerInstructionsForEnabledServers(): void
    {
        $this->servers->add('bricklayer', true);
        $this->client->instructions['bricklayer'] = 'Prefer SKUs over ids.';

        self::assertSame(['bricklayer' => 'Prefer SKUs over ids.'], $this->catalog()->serverInstructions());
    }

    #[Test]
    public function moduleDisabledYieldsNothing(): void
    {
        $this->servers->add('bricklayer', true);

        self::assertSame([], $this->catalog(false)->entries());
    }
}
