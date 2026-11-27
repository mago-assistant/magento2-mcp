<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Worker;

use MagoAssistant\Mcp\Service\Worker\BuildIdentity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BuildIdentityTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/build-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/app/etc', 0777, true);
        mkdir($this->root . '/pub/static', 0777, true);
        file_put_contents($this->root . '/app/etc/config.php', '<?php return [];');
        file_put_contents($this->root . '/pub/static/deployed_version.txt', '100');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function isStableForTheSameInstall(): void
    {
        self::assertSame(BuildIdentity::of($this->root), BuildIdentity::of($this->root));
    }

    #[Test]
    public function changesWithAnInPlaceDeploy(): void
    {
        $before = BuildIdentity::of($this->root);
        file_put_contents($this->root . '/pub/static/deployed_version.txt', '101');

        self::assertNotSame($before, BuildIdentity::of($this->root));
    }

    #[Test]
    public function changesWithTheModuleConfiguration(): void
    {
        $before = BuildIdentity::of($this->root);
        clearstatcache();
        touch($this->root . '/app/etc/config.php', time() + 5);

        self::assertNotSame($before, BuildIdentity::of($this->root));
    }
}
