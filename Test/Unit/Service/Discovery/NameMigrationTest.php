<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Discovery;

use MagoAssistant\Mcp\Service\Discovery\NameMigration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NameMigrationTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function row(string $name, bool $enabled): array
    {
        return ['name' => $name, 'enabled' => $enabled, 'source' => 'mcp_json'];
    }

    #[Test]
    public function cleanRowsNeedNothing(): void
    {
        $plan = NameMigration::plan([$this->row('acme', true), $this->row('widget', false)]);

        self::assertSame(['rename' => [], 'delete' => []], $plan);
    }

    #[Test]
    public function aLegacyHyphenNameIsRenamed(): void
    {
        $plan = NameMigration::plan([$this->row('acme-tools2', true), $this->row('magento_widget', false)]);

        self::assertSame(['acme-tools2' => 'acme_tools2', 'magento_widget' => 'widget'], $plan['rename']);
        self::assertSame([], $plan['delete']);
    }

    #[Test]
    public function onAClashTheEnabledRowWinsAndTakesTheName(): void
    {
        $plan = NameMigration::plan([$this->row('my-server', true), $this->row('my_server', false)]);

        self::assertSame(['my_server'], $plan['delete']);
        self::assertSame(['my-server' => 'my_server'], $plan['rename']);
    }

    #[Test]
    public function onAClashAnAlreadyCorrectEnabledRowStaysAndTheLegacyOneGoes(): void
    {
        $plan = NameMigration::plan([$this->row('my-server', false), $this->row('my_server', true)]);

        self::assertSame(['my-server'], $plan['delete']);
        self::assertSame([], $plan['rename']);
    }

    #[Test]
    public function whenNeitherIsEnabledTheCorrectlyNamedRowStays(): void
    {
        $plan = NameMigration::plan([$this->row('my-server', false), $this->row('my_server', false)]);

        self::assertSame(['my-server'], $plan['delete']);
        self::assertSame([], $plan['rename']);
    }

    #[Test]
    public function twoLegacyRowsForOneNameKeepOne(): void
    {
        $plan = NameMigration::plan([$this->row('a-b', false), $this->row('a.b', true)]);

        self::assertSame(['a-b'], $plan['delete']);
        self::assertSame(['a.b' => 'a_b'], $plan['rename']);
    }
}
