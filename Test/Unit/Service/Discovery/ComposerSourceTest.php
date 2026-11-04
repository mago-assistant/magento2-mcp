<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Discovery;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use MagoAssistant\Mcp\Service\Discovery\ComposerSource;
use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComposerSourceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mago-mcp-' . uniqid();
        mkdir($this->root . '/vendor/composer', 0777, true);
        mkdir($this->root . '/vendor/bin', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/vendor/bin/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_file($this->root . '/vendor/composer/installed.json')) {
            unlink($this->root . '/vendor/composer/installed.json');
        }
        rmdir($this->root . '/vendor/bin');
        rmdir($this->root . '/vendor/composer');
        rmdir($this->root . '/vendor');
        rmdir($this->root);
    }

    private function installed(array $packages): void
    {
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => $packages]));
    }

    private function source(): ComposerSource
    {
        return new ComposerSource(new DirectoryList($this->root), new File());
    }

    #[Test]
    public function usesExplicitExtraDeclaration(): void
    {
        $this->installed([['name' => 'acme/shipping', 'extra' => ['mago-mcp' => [
            'name' => 'Acme Shipping', 'command' => ['php', 'vendor/acme/shipping/bin/serve'], 'env' => ['ACME' => '1'],
        ]]]]);

        $servers = $this->source()->discover();

        self::assertCount(1, $servers);
        self::assertSame('acme_shipping', $servers[0]->name);
        self::assertSame(['php', 'vendor/acme/shipping/bin/serve'], $servers[0]->command);
        self::assertSame(['ACME' => '1'], $servers[0]->env);
        self::assertSame(DiscoveredServer::SOURCE_COMPOSER, $servers[0]->source);
    }

    #[Test]
    public function derivesNameAndCommandFromMcpBinaries(): void
    {
        touch($this->root . '/vendor/bin/widget-mcp');
        touch($this->root . '/vendor/bin/widget-mcp-docker');
        $this->installed([
            ['name' => 'acme/magento-widget', 'bin' => ['bin/widget', 'bin/widget-mcp', 'bin/widget-mcp-docker']],
            ['name' => 'other/tool', 'bin' => ['bin/tool']],
            ['name' => 'ghost/mcp-thing', 'bin' => ['bin/mcp-thing']],
        ]);

        $servers = $this->source()->discover();

        self::assertCount(1, $servers, 'only binaries that exist in vendor/bin count');
        self::assertSame('widget', $servers[0]->name);
        self::assertSame(['php', 'vendor/bin/widget-mcp'], $servers[0]->command);
    }

    #[Test]
    public function missingInstalledJsonYieldsNothing(): void
    {
        self::assertSame([], $this->source()->discover());
    }
}
