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
use MagoAssistant\Mcp\Service\Catalog\CatalogEntry;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Discovery\DefinitionRegistry;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use MagoAssistant\Mcp\Service\Tool\Mcp\Executor;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mcp\Service\Transport\TransportResolver;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeTransport;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeServerRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExecutorTest extends TestCase
{
    private FakeServerRepository $servers;
    private FakeTransport $transport;
    private FakeLogger $log;

    protected function setUp(): void
    {
        $this->servers = new FakeServerRepository();
        $this->transport = new FakeTransport();
        $this->log = new FakeLogger();
    }

    private function executor(): Executor
    {
        $config = new Config(new FakeScopeConfig([
            'mago/mcp/enabled' => '1',
            'mago/mcp/process_timeout' => '5',
            'mago/mcp/cache_lifetime' => '60',
            'mago/mcp/max_result_chars' => '16000',
        ]));
        $json = new Json();
        $magoConfig = $this->createStub(MagoConfig::class);
        $magoConfig->method('isDebugEnabled')->willReturn(false);
        $catalog = new ToolCatalog(
            $this->servers,
            new TransportResolver(['stdio' => $this->transport]),
            new FakeCache(),
            $config,
            new ModeClassifier(),
            new ErrorLogger($this->log, $json),
            new DefinitionRegistry()
        );

        return new Executor(
            $catalog,
            new TransportResolver(['stdio' => $this->transport]),
            $config,
            new DebugLogger($this->log, $json),
            new ErrorLogger($this->log, $json),
            $magoConfig
        );
    }

    private function entry(string $server = 'demo', string $tool = 'stock-set'): CatalogEntry
    {
        return new CatalogEntry(
            $server,
            $tool,
            'Set stock.',
            ['type' => 'object', 'properties' => [
                'qty' => ['type' => 'integer'],
                'price' => ['type' => 'number'],
                'active' => ['type' => 'boolean'],
                'sku' => ['type' => 'string'],
            ], 'required' => ['sku']],
            ModeClassifier::WRITE,
            CatalogEntry::ORIGIN_CLASSIFIER,
            false,
            false
        );
    }

    #[Test]
    public function problemWithValidatesCoercedArguments(): void
    {
        $executor = $this->executor();

        self::assertNull($executor->problemWith($this->entry(), ['sku' => 'A', 'qty' => '2']), 'coerced before validation');
        self::assertSame('missing required argument "sku"', $executor->problemWith($this->entry(), 'not an array'), 'a non-array is no arguments');
        self::assertSame('missing required argument "sku"', $executor->problemWith($this->entry(), []));
        self::assertSame('"qty" must be integer', $executor->problemWith($this->entry(), ['sku' => 'A', 'qty' => 'two']));
    }

    #[Test]
    public function oddSchemasRaiseNoWarning(): void
    {
        $entry = new CatalogEntry(
            'demo',
            'odd-get',
            'Odd schema.',
            ['type' => 'object', 'properties' => ['x' => true, 'y' => new \stdClass()], 'required' => 'x'],
            ModeClassifier::READ,
            CatalogEntry::ORIGIN_CLASSIFIER,
            false,
            false
        );
        $raised = [];
        set_error_handler(static function (int $errno, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });
        try {
            $problem = $this->executor()->problemWith($entry, ['x' => 1, 'y' => '2']);
        } finally {
            restore_error_handler();
        }

        self::assertNull($problem);
        self::assertSame([], $raised, 'no warning or notice');
    }

    #[Test]
    public function argumentsAreCoerced(): void
    {
        $arguments = $this->executor()->arguments(
            $this->entry(),
            ['sku' => '7', 'qty' => ' 3 ', 'price' => '2.5', 'active' => 'FALSE']
        );

        self::assertSame(['sku' => '7', 'qty' => 3, 'price' => 2.5, 'active' => false], $arguments);
        self::assertSame([], $this->executor()->arguments($this->entry(), '{"sku":"A"}'), 'a string is not decoded');
    }

    #[Test]
    public function runRefusesAServerThatIsNotEnabled(): void
    {
        $this->servers->add('demo', false);

        $result = $this->executor()->run($this->entry(), ['sku' => 'A']);

        self::assertStringContainsString('MCP server "demo" is not enabled', $result['error']);
        self::assertSame([], $this->transport->calls);
    }

    #[Test]
    public function runLogsAndReturnsAServerFailure(): void
    {
        $this->servers->add('demo', true);
        $this->transport->failures['demo:stock-set'] = 'server exploded';

        $result = $this->executor()->run($this->entry(), ['sku' => 'A']);

        self::assertSame(['error' => 'server exploded'], $result);
        self::assertStringContainsString('server exploded', $this->log->messages());
        self::assertStringContainsString('mcp_demo__stock_set', $this->log->messages());
    }

    #[Test]
    public function aNonPublicServerAlwaysAnswersWithText(): void
    {
        $this->servers->add('demo', true);
        $this->transport->nextResult = [
            'content' => [['type' => 'text', 'text' => '{"qty":3}']],
            'structuredContent' => ['qty' => 3],
            'isError' => false,
        ];

        $result = $this->executor()->run($this->entry(), ['sku' => 'A', 'qty' => 3]);

        self::assertSame(['server' => 'demo', 'action' => 'stock-set', 'result' => '{"qty":3}'], $result);
    }

    #[Test]
    public function aNonPublicServersStructuredOnlyResultIsJsonText(): void
    {
        $this->servers->add('demo', true);
        $this->transport->nextResult = ['content' => [], 'structuredContent' => ['qty' => 3, 'sku' => 'A'], 'isError' => false];

        $result = $this->executor()->run($this->entry(), ['sku' => 'A', 'qty' => 3]);

        self::assertSame('{"qty":3,"sku":"A"}', $result['result']);
    }

    #[Test]
    public function aPublicServerGetsStructuredContentAsData(): void
    {
        $this->servers->add('demo', true, 'composer', ['output_public' => true]);
        $this->transport->nextResult = [
            'content' => [['type' => 'text', 'text' => 'ignored when structured content exists']],
            'structuredContent' => ['qty' => 3],
            'isError' => false,
        ];

        self::assertSame(['qty' => 3], $this->executor()->run($this->entry(), ['sku' => 'A', 'qty' => 3])['result']);
    }

    #[Test]
    public function aPublicServersSingleJsonTextBlockIsDecoded(): void
    {
        $this->servers->add('demo', true, 'composer', ['output_public' => true]);
        $this->transport->nextResult = ['content' => [['type' => 'text', 'text' => '{"items":[1,2]}']], 'isError' => false];

        self::assertSame(['items' => [1, 2]], $this->executor()->run($this->entry(), ['sku' => 'A', 'qty' => 3])['result']);
    }

    #[Test]
    public function aPublicServersScalarJsonTextStaysText(): void
    {
        $this->servers->add('demo', true, 'composer', ['output_public' => true]);
        $this->transport->nextResult = ['content' => [['type' => 'text', 'text' => '42']], 'isError' => false];

        self::assertSame('42', $this->executor()->run($this->entry(), ['sku' => 'A', 'qty' => 3])['result']);
    }

    #[Test]
    public function aPublicServersTwoTextBlocksStayJoinedText(): void
    {
        $this->servers->add('demo', true, 'composer', ['output_public' => true]);
        $this->transport->nextResult = [
            'content' => [['type' => 'text', 'text' => '{"a":1}'], ['type' => 'text', 'text' => '{"b":2}']],
            'isError' => false,
        ];

        self::assertSame("{\"a\":1}\n\n{\"b\":2}", $this->executor()->run($this->entry(), ['sku' => 'A', 'qty' => 3])['result']);
    }

    #[Test]
    public function anErrorResultIsMagosErrorShape(): void
    {
        $this->servers->add('demo', true);
        $this->transport->nextResult = [
            'content' => [['type' => 'text', 'text' => 'This domain does not exist: shop.com']],
            'isError' => true,
        ];

        $result = $this->executor()->run($this->entry(), ['sku' => 'A', 'qty' => 3]);

        self::assertSame(['error' => 'This domain does not exist: shop.com'], $result, 'no hints configured on the row');
        self::assertArrayNotHasKey('is_error', $result);
    }

    #[Test]
    public function anErrorWithoutTextSaysTheToolReportedAnError(): void
    {
        $this->servers->add('demo', true);
        $this->transport->nextResult = ['content' => [], 'isError' => true];

        self::assertSame(
            ['error' => 'The MCP tool reported an error.'],
            $this->executor()->run($this->entry(), ['sku' => 'A', 'qty' => 3])
        );
    }

    #[Test]
    public function anErrorHintIsAppendedWhenItsFragmentMatches(): void
    {
        $executor = $this->executor();
        $method = new \ReflectionMethod($executor, 'withHint');
        $server = new ServerConfig('demo', ['php'], errorHints: ['does not exist' => 'List the domains first.', 'never' => 'unused']);

        self::assertSame('Domain does not exist. List the domains first.', $method->invoke($executor, $server, 'Domain does not exist.'));
        self::assertSame('Other failure', $method->invoke($executor, $server, 'Other failure'));
    }
}
