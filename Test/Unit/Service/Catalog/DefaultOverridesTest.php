<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Catalog;

use MagoAssistant\Mcp\Service\Catalog\DefaultOverrides;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use MagoAssistant\Mcp\Test\Unit\Fakes\BricklayerProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DefaultOverridesTest extends TestCase
{
    #[Test]
    public function disablesBricklayerExecutionSurfaces(): void
    {
        $defaults = BricklayerProfile::defaults();
        foreach (['code-runner', 'code-runner-help', 'batch-execute', 'reinitialize',
            'generate-module', 'generate-model', 'generate-controller', 'generate-api'] as $tool) {
            self::assertSame(ModeClassifier::DISABLED, $defaults->modeFor('bricklayer', $tool), $tool);
        }
    }

    #[Test]
    public function databaseQueryIsAReadByDefault(): void
    {
        self::assertSame(ModeClassifier::READ, BricklayerProfile::defaults()->modeFor('bricklayer', 'database-query'));
    }

    #[Test]
    public function personalDataToolsAreFlaggedButFollowTheClassifier(): void
    {
        $defaults = BricklayerProfile::defaults();
        foreach (['customer-get', 'order-list', 'invoice-create', 'shipment-list', 'creditmemo-list'] as $tool) {
            self::assertNull($defaults->modeFor('bricklayer', $tool), $tool);
            self::assertTrue($defaults->isPersonalData('bricklayer', $tool), $tool);
        }
        self::assertFalse($defaults->isPersonalData('bricklayer', 'product-get'));
    }

    #[Test]
    public function leavesOtherToolsAndServersAlone(): void
    {
        $defaults = BricklayerProfile::defaults();
        self::assertNull($defaults->modeFor('bricklayer', 'product-list'));
        self::assertNull($defaults->modeFor('other-server', 'code-runner'));
        self::assertNull($defaults->modeFor('other-server', 'database-query'));
    }

    #[Test]
    public function withoutProfilesNothingIsOverriddenOrFlagged(): void
    {
        $defaults = new DefaultOverrides();
        self::assertNull($defaults->modeFor('bricklayer', 'code-runner'));
        self::assertNull($defaults->modeFor('bricklayer', 'database-query'));
        self::assertFalse($defaults->isPersonalData('bricklayer', 'customer-get'));
    }

    #[Test]
    public function profilesAreKeyedByServerName(): void
    {
        $defaults = new DefaultOverrides(['acme' => ['wipe']], ['acme' => ['peek']], ['acme' => ['secret-']]);
        self::assertSame(ModeClassifier::DISABLED, $defaults->modeFor('acme', 'wipe'));
        self::assertSame(ModeClassifier::READ, $defaults->modeFor('acme', 'peek'));
        self::assertTrue($defaults->isPersonalData('acme', 'secret-thing'));
        self::assertNull($defaults->modeFor('other', 'wipe'));
    }
}
