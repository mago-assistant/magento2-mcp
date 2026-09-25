<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Chat;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as MagoConfig;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Chat\ToolEventRelabeler;
use MagoAssistant\Mcp\Service\Tool\Mcp\Executor;
use MagoAssistant\Mcp\Service\Tool\Mcp\SkillRegistry;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mcp\Service\Transport\TransportResolver;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeTransport;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeServerRepository;
use MagoAssistant\Mcp\Test\Unit\Fakes\ThrowingSkillRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolEventRelabelerTest extends TestCase
{
    /** @var array<int,array{0:string,1:array}> */
    private array $emitted = [];

    private function recorder(): callable
    {
        return function (string $event, array $data): void {
            $this->emitted[] = [$event, $data];
        };
    }

    private function registry(): SkillRegistry
    {
        $servers = new FakeServerRepository();
        $transport = new FakeTransport();
        $log = new FakeLogger();
        $transport->tools['demo'] = [
            ['name' => 'product-list', 'description' => 'List products.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'code-runner', 'description' => 'Run PHP.', 'inputSchema' => ['type' => 'object']],
        ];
        $servers->add('demo', true);
        $config = new Config(new FakeScopeConfig([
            'mago/mcp/enabled' => '1',
            'mago/mcp/process_timeout' => '5',
            'mago/mcp/cache_lifetime' => '60',
            'mago/mcp/max_result_chars' => '16000',
        ]));
        $json = new Json();
        $errorLogger = new ErrorLogger($log, $json);
        $catalog = new ToolCatalog(
            $servers,
            new TransportResolver(['stdio' => $transport]),
            new FakeCache(),
            $config,
            new ModeClassifier(),
            $errorLogger
        );
        $executor = new Executor(
            $catalog,
            new TransportResolver(['stdio' => $transport]),
            $config,
            new DebugLogger($log, $json),
            $errorLogger,
            $this->createStub(MagoConfig::class)
        );

        return new SkillRegistry($catalog, $executor, $errorLogger, new ModeClassifier());
    }

    private function relabeler(): ToolEventRelabeler
    {
        return new ToolEventRelabeler($this->registry());
    }

    #[Test]
    public function anMcpSkillsRunningFallbackBecomesAPlainLanguagePhrase(): void
    {
        $wrapped = $this->relabeler()->wrap($this->recorder());

        $wrapped('tool_status', ['name' => 'demo__product-list', 'status' => 'running', 'message' => 'Running demo__product-list...']);

        self::assertSame(
            [['tool_status', ['name' => 'demo__product-list', 'status' => 'running', 'message' => 'Listing product...']]],
            $this->emitted
        );
    }

    #[Test]
    public function aSkillWithNoKnownVerbFallsBackToRunningTheWords(): void
    {
        $wrapped = $this->relabeler()->wrap($this->recorder());

        $wrapped('tool_status', ['name' => 'demo__code-runner', 'status' => 'running', 'message' => 'Running demo__code-runner...']);

        self::assertSame('Running code runner...', $this->emitted[0][1]['message']);
    }

    #[Test]
    public function aMagoToolsRunningStatusPassesThroughUntouched(): void
    {
        $wrapped = $this->relabeler()->wrap($this->recorder());

        $wrapped('tool_status', ['name' => 'sales_data', 'status' => 'running', 'message' => 'Running sales_data...']);

        self::assertSame(
            [['tool_status', ['name' => 'sales_data', 'status' => 'running', 'message' => 'Running sales_data...']]],
            $this->emitted
        );
    }

    #[Test]
    public function aToolCallEventPassesThroughUntouched(): void
    {
        $wrapped = $this->relabeler()->wrap($this->recorder());

        $wrapped('tool_call', ['id' => 'c1', 'name' => 'demo__product-list', 'input' => ['sku' => 'A']]);

        self::assertSame(
            [['tool_call', ['id' => 'c1', 'name' => 'demo__product-list', 'input' => ['sku' => 'A']]]],
            $this->emitted
        );
    }

    #[Test]
    public function aRunningStatusWithACustomMessageIsNeverRewritten(): void
    {
        $wrapped = $this->relabeler()->wrap($this->recorder());

        $wrapped('tool_status', ['name' => 'demo__product-list', 'status' => 'running', 'message' => 'Almost there...']);

        self::assertSame('Almost there...', $this->emitted[0][1]['message']);
    }

    #[Test]
    public function aDoneStatusPassesThroughUntouched(): void
    {
        $wrapped = $this->relabeler()->wrap($this->recorder());

        $wrapped('tool_status', ['name' => 'demo__product-list', 'status' => 'done', 'duration_ms' => 12]);

        self::assertSame(
            [['tool_status', ['name' => 'demo__product-list', 'status' => 'done', 'duration_ms' => 12]]],
            $this->emitted
        );
    }

    #[Test]
    public function aFailingSkillLookupLeavesTheStatusLineAsMagoSentIt(): void
    {
        $wrapped = (new ToolEventRelabeler(new ThrowingSkillRegistry()))->wrap($this->recorder());

        $wrapped('tool_status', ['name' => 'demo__product-list', 'status' => 'running', 'message' => 'Running demo__product-list...']);
        $wrapped('tool_status', ['name' => 'sales_data', 'status' => 'running', 'message' => 'Running sales_data...']);

        self::assertSame(
            [
                ['tool_status', ['name' => 'demo__product-list', 'status' => 'running', 'message' => 'Running demo__product-list...']],
                ['tool_status', ['name' => 'sales_data', 'status' => 'running', 'message' => 'Running sales_data...']],
            ],
            $this->emitted
        );
    }
}
