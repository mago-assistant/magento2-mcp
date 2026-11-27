<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Worker;

use MagoAssistant\Mcp\Service\Worker\Starter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StarterTest extends TestCase
{
    #[Test]
    public function cronsShortPathGetsTheUsualDirectories(): void
    {
        $env = Starter::environment(['PATH' => '/usr/bin:/bin', 'HOME' => '/var/www']);

        self::assertSame('/var/www', $env['HOME']);
        self::assertSame(['/usr/bin', '/bin', '/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/sbin'], explode(':', $env['PATH']));
    }

    #[Test]
    public function aMissingPathIsSet(): void
    {
        self::assertSame('/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', Starter::environment([])['PATH']);
    }
}
