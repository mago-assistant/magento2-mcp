<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Chat;

use MagoAssistant\Mcp\Service\Chat\ToolEventRelabeler;
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

    #[Test]
    public function relabelsTheAddonsOwnEventsAndLeavesOthersAlone(): void
    {
        $wrapped = (new ToolEventRelabeler())->wrap($this->recorder());

        $wrapped('tool_call', ['id' => 'c1', 'name' => 'mcp', 'input' => ['action' => 'bricklayer:product-list', 'arguments' => []]]);
        $wrapped('tool_call', ['id' => 'c2', 'name' => 'sales_data', 'input' => ['action' => 'recent_orders']]);
        $wrapped('tool_status', ['name' => 'mcp', 'status' => 'running', 'message' => 'Running mcp...']);
        $wrapped('tool_status', ['name' => 'mcp', 'status' => 'done', 'duration_ms' => 12]);
        $wrapped('tool_status', ['name' => 'sales_data', 'status' => 'running', 'message' => 'Fetching recent orders...']);
        $wrapped('text', ['text' => 'hi']);

        self::assertSame([
            ['tool_call', ['id' => 'c1', 'name' => 'mcp:bricklayer:product-list', 'input' => ['action' => 'bricklayer:product-list', 'arguments' => []]]],
            ['tool_call', ['id' => 'c2', 'name' => 'sales_data', 'input' => ['action' => 'recent_orders']]],
            ['tool_status', ['name' => 'mcp:bricklayer:product-list', 'status' => 'running', 'message' => 'Listing product...']],
            ['tool_status', ['name' => 'mcp:bricklayer:product-list', 'status' => 'done', 'duration_ms' => 12]],
            ['tool_status', ['name' => 'sales_data', 'status' => 'running', 'message' => 'Fetching recent orders...']],
            ['text', ['text' => 'hi']],
        ], $this->emitted);
    }

    #[Test]
    public function severalCallsInFlightKeepTheGenericNameRatherThanGuessing(): void
    {
        $wrapped = (new ToolEventRelabeler())->wrap($this->recorder());

        $wrapped('tool_call', ['id' => 'a', 'name' => 'mcp', 'input' => ['action' => 'bricklayer:category-tree']]);
        $wrapped('tool_call', ['id' => 'b', 'name' => 'mcp', 'input' => ['action' => 'magerun:cache_list']]);
        // Mago denied call "a": it emits no status for it, so the next status belongs to "b".
        $wrapped('tool_status', ['name' => 'mcp', 'status' => 'running', 'message' => 'Running mcp...']);
        $wrapped('tool_status', ['name' => 'mcp', 'status' => 'done']);

        self::assertSame('mcp:bricklayer:category-tree', $this->emitted[0][1]['name'], 'tags are always exact');
        self::assertSame('mcp:magerun:cache_list', $this->emitted[1][1]['name']);
        self::assertSame('mcp', $this->emitted[2][1]['name'], 'ambiguous: never guess a label');
        self::assertSame('Running mcp...', $this->emitted[2][1]['message']);
        self::assertSame('mcp', $this->emitted[3][1]['name']);
    }

    #[Test]
    public function aFailureMessageIsNeverRewrittenAndNoQueueMeansNoLabel(): void
    {
        $wrapped = (new ToolEventRelabeler())->wrap($this->recorder());

        $wrapped('tool_call', ['id' => 'a', 'name' => 'mcp', 'input' => ['action' => 'bricklayer:category-tree']]);
        $wrapped('tool_status', ['name' => 'mcp', 'status' => 'running', 'message' => 'Running mcp...']);
        $wrapped('tool_status', ['name' => 'mcp', 'status' => 'failed', 'message' => 'boom']);
        $wrapped('tool_status', ['name' => 'mcp', 'status' => 'running', 'message' => 'Running mcp...']);

        self::assertSame('mcp:bricklayer:category-tree', $this->emitted[1][1]['name']);
        self::assertSame('mcp:bricklayer:category-tree', $this->emitted[2][1]['name']);
        self::assertSame('boom', $this->emitted[2][1]['message']);
        self::assertSame('mcp', $this->emitted[3][1]['name'], 'nothing queued: the name stays');
    }

    #[Test]
    public function unparsableActionsKeepTheToolName(): void
    {
        $wrapped = (new ToolEventRelabeler())->wrap($this->recorder());

        $wrapped('tool_call', ['id' => 'a', 'name' => 'mcp', 'input' => ['action' => 'none']]);
        $wrapped('tool_call', ['id' => 'b', 'name' => 'mcp', 'input' => 'garbage']);

        self::assertSame('mcp', $this->emitted[0][1]['name']);
        self::assertSame('mcp', $this->emitted[1][1]['name']);
    }

    #[Test]
    public function confirmedCallsPreSeedTheQueueWithSelectedCallsOnly(): void
    {
        $confirmed = [
            ['id' => 'x', 'name' => 'mcp', 'input' => ['action' => 'bricklayer:product-delete', 'arguments' => ['sku' => 'A']]],
            ['id' => 'y', 'name' => 'mcp', 'input' => ['action' => 'bricklayer:category-delete', 'arguments' => ['categoryId' => 3]]],
            ['id' => 'z', 'name' => 'cms_data', 'input' => ['action' => 'update_page']],
        ];
        // The admin left "x" unticked: Mago runs only "y" and emits a status only for it.
        $wrapped = (new ToolEventRelabeler())->wrap($this->recorder(), $confirmed, ['y', 'z']);

        $wrapped('tool_status', ['name' => 'mcp', 'status' => 'running', 'message' => 'Running mcp...']);
        $wrapped('tool_status', ['name' => 'mcp', 'status' => 'done']);

        self::assertSame('mcp:bricklayer:category-delete', $this->emitted[0][1]['name']);
        self::assertSame('Deleting category...', $this->emitted[0][1]['message']);
        self::assertSame('mcp:bricklayer:category-delete', $this->emitted[1][1]['name']);

        $this->emitted = [];
        $wrapped = (new ToolEventRelabeler())->wrap($this->recorder(), $confirmed, null);
        $wrapped('tool_status', ['name' => 'mcp', 'status' => 'running', 'message' => 'Running mcp...']);
        self::assertSame('mcp', $this->emitted[0][1]['name'], 'two selected mcp calls: ambiguous, stay generic');
    }

    #[Test]
    public function statusLinesReadLikeMagosOwn(): void
    {
        $wrapped = (new ToolEventRelabeler())->wrap($this->recorder());
        foreach (['bricklayer:order-get', 'magerun:sys_cron_run', 'bricklayer:application-info', 'bricklayer:category-assign-products'] as $action) {
            $wrapped('tool_call', ['id' => $action, 'name' => 'mcp', 'input' => ['action' => $action]]);
            $wrapped('tool_status', ['name' => 'mcp', 'status' => 'running', 'message' => 'Running mcp...']);
            $wrapped('tool_status', ['name' => 'mcp', 'status' => 'done']);
        }
        $messages = array_values(array_map(static fn ($e) => $e[1]['message'], array_filter($this->emitted, static fn ($e) => $e[0] === 'tool_status' && $e[1]['status'] === 'running')));

        self::assertSame(['Getting order...', 'Running sys cron...', 'Running application info...', 'Assigning category products...'], $messages);
        self::assertSame('mcp:magerun:sys_cron_run', $this->emitted[3][1]['name']);
    }
}
