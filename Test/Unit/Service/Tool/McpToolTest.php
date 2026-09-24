<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Tool;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as MagoConfig;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Tool\McpTool;
use MagoAssistant\Mcp\Test\Unit\Fakes\BricklayerProfile;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeMcpClient;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeServerRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class McpToolTest extends TestCase
{
    private FakeServerRepository $servers;
    private FakeMcpClient $client;
    private FakeLogger $log;
    private int $maxChars = 16000;

    protected function setUp(): void
    {
        $this->servers = new FakeServerRepository();
        $this->client = new FakeMcpClient();
        $this->log = new FakeLogger();
        $this->client->tools['bricklayer'] = [
            ['name' => 'product-list', 'description' => 'List products with filters. Supports paging.',
                'inputSchema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer'], 'sku' => ['type' => 'string']], 'required' => ['limit']]],
            ['name' => 'product-delete', 'description' => 'Delete a product by SKU.',
                'inputSchema' => ['type' => 'object', 'properties' => ['sku' => ['type' => 'string']], 'required' => ['sku']]],
            ['name' => 'code-runner', 'description' => 'Run PHP.', 'inputSchema' => ['type' => 'object']],
        ];
    }

    private function tool(): McpTool
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
            $this->client,
            new FakeCache(),
            $config,
            new ModeClassifier(),
            BricklayerProfile::defaults(),
            new ErrorLogger($this->log, $json)
        );

        return new McpTool(
            $catalog,
            $this->client,
            $config,
            new DebugLogger($this->log, $json),
            new ErrorLogger($this->log, $json),
            $magoConfig
        );
    }

    #[Test]
    public function schemaListsCallableActionsWithOneLineDescriptions(): void
    {
        $this->servers->add('bricklayer', true);
        $schema = $this->tool()->getParameterSchema();

        self::assertSame(['bricklayer:product-list', 'bricklayer:product-delete'], $schema['properties']['action']['enum']);
        self::assertStringContainsString('bricklayer:product-list — List products with filters.', $schema['properties']['action']['description']);
        self::assertStringNotContainsString('Supports paging', $schema['properties']['action']['description']);
        self::assertSame(['action'], $schema['required']);
        self::assertTrue($schema['properties']['arguments']['additionalProperties']);
        self::assertStringContainsString('bricklayer (2 actions)', $this->tool()->getDescription());
    }

    #[Test]
    public function narrowsToGivenActions(): void
    {
        $this->servers->add('bricklayer', true);
        $tool = $this->tool();

        $schema = $tool->getParameterSchemaForActions(['bricklayer:product-list']);
        self::assertSame(['bricklayer:product-list'], $schema['properties']['action']['enum']);
        self::assertStringContainsString('bricklayer (1 action)', $tool->getDescriptionForActions(['bricklayer:product-list']));
    }

    #[Test]
    public function readWriteIrreversibleAndAclFollowTheCatalog(): void
    {
        $this->servers->add('bricklayer', true);
        $tool = $this->tool();

        self::assertFalse($tool->isReadOnly());
        self::assertTrue($tool->isReadOnlyAction(['action' => 'bricklayer:product-list']));
        self::assertFalse($tool->isReadOnlyAction(['action' => 'bricklayer:product-delete']));
        self::assertFalse($tool->isReadOnlyAction(['action' => 'unknown']));
        self::assertTrue($tool->isIrreversibleAction(['action' => 'bricklayer:product-delete']));
        self::assertSame('MagoAssistant_Mcp::use', $tool->getMagentoAcl(['action' => 'bricklayer:product-list']));
        self::assertSame('MagoAssistant_Mcp::use_write', $tool->getMagentoAcl([]));
        self::assertCount(2, $tool->getImpacts(['action' => 'bricklayer:product-delete', 'arguments' => ['sku' => 'X']], 1));
    }

    #[Test]
    public function refusesInvalidWritesBeforeConfirmation(): void
    {
        $this->servers->add('bricklayer', true);
        $tool = $this->tool();

        self::assertNull($tool->findRefusal(['action' => 'bricklayer:product-delete', 'arguments' => ['sku' => 'A']]));
        $refusal = $tool->findRefusal(['action' => 'bricklayer:product-delete', 'arguments' => []]);
        self::assertStringContainsString('missing required argument "sku"', $refusal['error']);
        self::assertStringContainsString('Unknown or disabled MCP action', $tool->findRefusal(['action' => 'bricklayer:code-runner'])['error']);
        self::assertSame([], $this->client->calls, 'a refusal never reaches the server');
    }

    #[Test]
    public function executesAndShapesTheResult(): void
    {
        $this->servers->add('bricklayer', true);
        $this->client->nextResult = ['content' => [['type' => 'text', 'text' => 'one'], ['type' => 'text', 'text' => 'two']], 'isError' => false];

        $result = $this->tool()->execute(['action' => 'bricklayer:product-list', 'arguments' => ['limit' => 5]]);

        self::assertSame(['bricklayer', 'product-list', ['limit' => 5]], $this->client->calls[0]);
        self::assertSame(['server' => 'bricklayer', 'action' => 'product-list', 'content' => "one\n\ntwo", 'is_error' => false], $result);
        self::assertStringContainsString('MCP call', $this->log->messages(), 'debug logging when Mago debug is on');
    }

    #[Test]
    public function stringArgumentsAreDecoded(): void
    {
        $this->servers->add('bricklayer', true);

        $this->tool()->execute(['action' => 'bricklayer:product-list', 'arguments' => '{"limit": 3}']);
        self::assertSame(['limit' => 3], $this->client->calls[0][2]);

        $result = $this->tool()->execute(['action' => 'bricklayer:product-list', 'arguments' => 'not json']);
        self::assertStringContainsString('Invalid arguments', $result['error']);
    }

    #[Test]
    public function numericStringsAreCoercedToTheSchemaType(): void
    {
        $this->servers->add('bricklayer', true);

        $this->tool()->execute(['action' => 'bricklayer:product-list', 'arguments' => ['limit' => '3', 'sku' => '42']]);

        self::assertSame(['limit' => 3, 'sku' => '42'], $this->client->calls[0][2], 'integer coerced, string left alone');
    }

    #[Test]
    public function validatesRequiredKeysAndTypesAndShowsTheSchema(): void
    {
        $this->servers->add('bricklayer', true);
        $tool = $this->tool();

        $missing = $tool->execute(['action' => 'bricklayer:product-list', 'arguments' => []]);
        self::assertStringContainsString('missing required argument "limit"', $missing['error']);
        self::assertStringContainsString('"required":["limit"]', $missing['error']);

        $wrongType = $tool->execute(['action' => 'bricklayer:product-list', 'arguments' => ['limit' => 'five']]);
        self::assertStringContainsString('"limit" must be integer', $wrongType['error']);
        self::assertSame([], $this->client->calls);
    }

    #[Test]
    public function unknownDisabledAndFailingActionsReturnErrors(): void
    {
        $this->servers->add('bricklayer', true);
        $this->client->failures['bricklayer:product-delete'] = 'server exploded';
        $tool = $this->tool();

        self::assertStringContainsString('Unknown or disabled MCP action "bricklayer:code-runner"', $tool->execute(['action' => 'bricklayer:code-runner'])['error']);
        self::assertStringContainsString('Valid actions: bricklayer:product-list', $tool->execute(['action' => 'x'])['error']);
        self::assertSame(['error' => 'server exploded'], $tool->execute(['action' => 'bricklayer:product-delete', 'arguments' => ['sku' => 'A']]));
        self::assertStringContainsString('server exploded', $this->log->messages(), 'failures reach the error log');
    }

    #[Test]
    public function isErrorAndTruncationAreReported(): void
    {
        $this->servers->add('bricklayer', true);
        $this->maxChars = 10;
        $this->client->nextResult = ['content' => [['type' => 'text', 'text' => str_repeat('x', 25)]], 'isError' => true];

        $result = $this->tool()->execute(['action' => 'bricklayer:product-list', 'arguments' => ['limit' => 1]]);

        self::assertTrue($result['is_error']);
        self::assertSame(str_repeat('x', 10) . ' [truncated: 15 more characters]', $result['content']);
    }

    #[Test]
    public function resultWithoutTextBlocksSaysSo(): void
    {
        $this->servers->add('bricklayer', true);
        $this->client->nextResult = ['content' => [['type' => 'image', 'data' => 'AAAA', 'mimeType' => 'image/png']], 'isError' => false];

        $result = $this->tool()->execute(['action' => 'bricklayer:product-list', 'arguments' => ['limit' => 1]]);

        self::assertSame('(no text content: 1 image block)', $result['content']);
    }

    #[Test]
    public function noServersYieldsValidSchemaAndRefuses(): void
    {
        $tool = $this->tool();

        self::assertSame(['none'], $tool->getParameterSchema()['properties']['action']['enum']);
        self::assertStringContainsString('Do not call this tool', $tool->getDescription());
        self::assertSame('', $tool->getInstructions());
        self::assertArrayHasKey('error', $tool->execute(['action' => 'none']));
    }

    #[Test]
    public function instructionsCarryServerNotesAndEachActionsSchema(): void
    {
        $this->servers->add('bricklayer', true);
        $this->client->instructions['bricklayer'] = 'Prefer SKUs over ids.';
        $tool = $this->tool();

        $instructions = $tool->getInstructions();
        self::assertStringContainsString("## Server bricklayer\nPrefer SKUs over ids.", $instructions);
        self::assertStringContainsString('## bricklayer:product-list', $instructions);
        self::assertStringContainsString('"required":["sku"]', $instructions);
        self::assertSame(
            ['server' => [PiiClass::PUBLIC], 'action' => [PiiClass::PUBLIC], 'content' => [PiiClass::PUBLIC], 'is_error' => [PiiClass::PUBLIC]],
            $tool->getFieldClassification()
        );
    }
}
