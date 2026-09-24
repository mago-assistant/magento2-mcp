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
    public function validatesModes(): void
    {
        $classifier = new ModeClassifier();
        self::assertTrue($classifier->isValidMode('read'));
        self::assertTrue($classifier->isValidMode('disabled'));
        self::assertFalse($classifier->isValidMode('READ'));
        self::assertFalse($classifier->isValidMode(''));
    }
}
