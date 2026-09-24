<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Discovery;

use MagoAssistant\Mcp\Service\Discovery\DockerExecUnwrapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DockerExecUnwrapperTest extends TestCase
{
    #[Test]
    public function unwrapsThisRepositoriesBricklayerEntry(): void
    {
        $result = (new DockerExecUnwrapper())->unwrap('docker', ['exec', '-i', '-u', 'app', '-w', '/var/www/magento',
            '-e', 'BRICKLAYER_MAGENTO_ROOT=/var/www/magento', 'php', 'php', '/var/www/magento/vendor/bin/bricklayer-mcp'], []);

        self::assertSame(['php', '/var/www/magento/vendor/bin/bricklayer-mcp'], $result['command']);
        self::assertSame(['BRICKLAYER_MAGENTO_ROOT' => '/var/www/magento'], $result['env']);
        self::assertSame('/var/www/magento', $result['cwd']);
    }

    #[Test]
    public function keepsExistingEnvAndHandlesCombinedFlags(): void
    {
        $result = (new DockerExecUnwrapper())->unwrap('docker', ['exec', '-it', 'web', 'node', 'server.js'], ['A' => '1']);

        self::assertSame(['node', 'server.js'], $result['command']);
        self::assertSame(['A' => '1'], $result['env']);
        self::assertNull($result['cwd']);
    }

    #[Test]
    public function returnsNullForNonDockerAndForEmptyInnerCommand(): void
    {
        $unwrapper = new DockerExecUnwrapper();
        self::assertNull($unwrapper->unwrap('php', ['x.php'], []));
        self::assertNull($unwrapper->unwrap('docker', ['run', 'img'], []));
        self::assertNull($unwrapper->unwrap('docker', ['exec', '-i', 'web'], []));
    }
}
