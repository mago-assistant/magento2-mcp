<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Catalog;

use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModeClassifierTest extends TestCase
{
    public static function names(): iterable
    {
        yield 'get' => ['product-get', ModeClassifier::READ];
        yield 'list' => ['order-list', ModeClassifier::READ];
        yield 'tree' => ['category-tree', ModeClassifier::READ];
        yield 'underscore' => ['system_status', ModeClassifier::READ];
        yield 'search' => ['search-docs', ModeClassifier::READ];
        yield 'check' => ['check-class', ModeClassifier::READ];
        yield 'create' => ['product-create', ModeClassifier::WRITE];
        yield 'delete' => ['category-delete', ModeClassifier::WRITE];
        yield 'assign' => ['category-assign-products', ModeClassifier::WRITE];
        yield 'write beats read' => ['search-index-update', ModeClassifier::WRITE];
        yield 'plain write' => ['product-stock-update', ModeClassifier::WRITE];
        yield 'products' => ['category-products', ModeClassifier::READ];
        yield 'configuration' => ['di-configuration', ModeClassifier::READ];
        yield 'runner' => ['code-runner', ModeClassifier::WRITE];
        yield 'unknown fails closed' => ['reindex', ModeClassifier::WRITE];
        yield 'empty fails closed' => ['', ModeClassifier::WRITE];
        yield 'query can run any SQL' => ['database-query', ModeClassifier::WRITE];
        yield 'clear beats log' => ['log-clear', ModeClassifier::WRITE];
        yield 'regenerate beats rewrites' => ['url-rewrites-regenerate', ModeClassifier::WRITE];
        yield 'reindex beats products' => ['products-reindex', ModeClassifier::WRITE];
        yield 'reindex beats search' => ['search-reindex', ModeClassifier::WRITE];
        yield 'rebuild beats tree' => ['category-tree-rebuild', ModeClassifier::WRITE];
        yield 'reset beats configuration' => ['configuration-reset', ModeClassifier::WRITE];
        yield 'purge beats log' => ['log-purge', ModeClassifier::WRITE];
        yield 'truncate beats log' => ['log-truncate', ModeClassifier::WRITE];
        yield 'drop beats schema' => ['schema-drop', ModeClassifier::WRITE];
        yield 'verb first' => ['get-product', ModeClassifier::READ];
        yield 'noun alone' => ['log', ModeClassifier::READ];
        yield 'noun last' => ['order-items', ModeClassifier::READ];
        yield 'noun then unlisted verb' => ['log-wipe', ModeClassifier::WRITE];
        yield 'noun first then unlisted verb' => ['status-change', ModeClassifier::WRITE];
        yield 'read verb in the middle' => ['cache-list-wipe', ModeClassifier::WRITE];
    }

    #[Test]
    #[DataProvider('names')]
    public function classifiesByNameSegments(string $name, string $expected): void
    {
        self::assertSame($expected, (new ModeClassifier())->classify($name));
    }

    #[Test]
    public function detectsIrreversibleNames(): void
    {
        $classifier = new ModeClassifier();
        self::assertTrue($classifier->isIrreversible('product-delete'));
        self::assertTrue($classifier->isIrreversible('order-cancel'));
        self::assertTrue($classifier->isIrreversible('creditmemo-create'));
        self::assertTrue($classifier->isIrreversible('tag_remove'));
        self::assertFalse($classifier->isIrreversible('product-update'));
    }

    #[Test]
    public function flagsPersonalDataByWholeSegmentsOnly(): void
    {
        $classifier = new ModeClassifier();

        foreach (['customer-get', 'order-list', 'invoice-create', 'shipment_list', 'creditmemo-list',
            'customer-address-update', 'subscriber-export', 'cart-get', 'review-list', 'user-info'] as $tool) {
            self::assertTrue($classifier->isPersonalData($tool), $tool);
        }
        foreach (['product-get', 'preorder-list', 'url-rewrites', 'cache-flush', 'sys_cron_run', ''] as $tool) {
            self::assertFalse($classifier->isPersonalData($tool), $tool);
        }
    }

    #[Test]
    public function namesEndingInAReadWordButCarryingAWriteVerbAreWrites(): void
    {
        $classifier = new ModeClassifier();

        foreach (['admin_user_change-status', 'mark_all_notifications_read', 'export_orders',
            'search_and_replace', 'toggle-status', 'enable-module', 'refund-order', 'close_issue'] as $tool) {
            self::assertSame(ModeClassifier::WRITE, $classifier->classify($tool), $tool);
        }
        self::assertSame(ModeClassifier::READ, $classifier->classify('order-status'), 'no verb, ends in a read noun');
    }

    #[Test]
    public function destructiveVerbsBeyondCrudAreWrites(): void
    {
        $classifier = new ModeClassifier();

        foreach (['erase-customer-addresses', 'wipe-orders', 'revoke-token', 'restore-backup'] as $tool) {
            self::assertSame(ModeClassifier::WRITE, $classifier->classify($tool), $tool);
        }
        self::assertSame(ModeClassifier::READ, $classifier->classify('order-list'));
        self::assertSame(ModeClassifier::READ, $classifier->classify('cms-block-get'), 'block is a noun, not a write word');
    }

    #[Test]
    public function recognisesExecutionSurfacesByWholeSegments(): void
    {
        $classifier = new ModeClassifier();

        foreach (['code-runner', 'batch-execute', 'execute_sql', 'db_console', 'browser_evaluate', 'bash',
            'generate-module', 'reinitialize', 'setup_upgrade', 'run_command', 'php-eval'] as $tool) {
            self::assertTrue($classifier->isExecutionSurface($tool), $tool);
        }
        foreach (['order-cancel', 'product-update', 'coupon-code-list', 'deploy_mode_show',
            'drop_table', 'product-list', 'query-docs', ''] as $tool) {
            self::assertFalse($classifier->isExecutionSurface($tool), $tool);
        }
        foreach (['database-query', 'db_query', 'database-schema', 'db_info'] as $tool) {
            self::assertTrue($classifier->isExecutionSurface($tool), $tool . ': raw database access is an execution surface');
        }
        self::assertTrue($classifier->isIrreversible('drop_table'));
        self::assertTrue($classifier->isIrreversible('truncate-logs'));
    }

    #[Test]
    public function tellsAWriteWordFromAFailClosedWrite(): void
    {
        $classifier = new ModeClassifier();

        foreach (['product-delete', 'order-cancel', 'sys_cron_kill', 'sys_cron_schedule', 'log-clear'] as $tool) {
            self::assertTrue($classifier->hasWriteWord($tool), $tool);
            self::assertSame(ModeClassifier::WRITE, $classifier->classify($tool));
        }
        foreach (['resolve-library-id', 'query-docs', 'rotate', 'product-list', ''] as $tool) {
            self::assertFalse($classifier->hasWriteWord($tool), $tool . ' is a write by failing closed, or a read');
        }
    }

    #[Test]
    public function recognisesCredentialToolsByWholeSegments(): void
    {
        $classifier = new ModeClassifier();

        foreach (['config_env_show', 'env-show', 'credentials-get', 'secret_get', 'config_store_get_decrypted',
            'api-key-list', 'token-list', 'db_dsn'] as $tool) {
            self::assertTrue($classifier->isSensitive($tool), $tool);
        }
        foreach (['config_store_get', 'product-list', 'keyword-search', 'tokenise-text', ''] as $tool) {
            self::assertFalse($classifier->isSensitive($tool), $tool);
        }
    }
}
