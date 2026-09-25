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
            new ErrorLogger($this->log, $json)
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
}
