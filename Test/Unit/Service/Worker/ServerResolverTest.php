<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Worker;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as MagoConfig;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Mcp\Channel\ChannelFactory;
use MagoAssistant\Mcp\Service\Mcp\StdioSessionFactory;
use MagoAssistant\Mcp\Service\Privacy\SecretScrub;
use MagoAssistant\Mcp\Service\Worker\ServerResolver;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeServerRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;

final class ServerResolverTest extends TestCase
{
    private FakeServerRepository $servers;
    private ServerResolver $resolver;

    protected function setUp(): void
    {
        $this->servers = new FakeServerRepository();
        $magoConfig = $this->createStub(MagoConfig::class);
        $config = new Config(new FakeScopeConfig([Config::XML_PATH_PROCESS_TIMEOUT => 45]));
        $sessions = new StdioSessionFactory(
            new DirectoryList(sys_get_temp_dir()),
            new DebugLogger(new FakeLogger(), new Json(), $magoConfig),
            $magoConfig,
            new PhpExecutableFinder(),
            new SecretScrub(),
            new ChannelFactory($config, $this->createStub(DeploymentConfig::class), 'cli')
        );
        $this->resolver = new ServerResolver($this->servers, $config, $sessions);
    }

    #[Test]
    public function anEnabledLocalServerResolvesToItsCommandWithPhpMadeAbsolute(): void
    {
        $this->servers->add('bricklayer', true, 'composer', ['command' => ['php', 'vendor/bin/bricklayer-mcp'], 'timeout' => 90]);

        $server = ($this->resolver)('bricklayer');

        self::assertNotNull($server);
        self::assertSame('vendor/bin/bricklayer-mcp', $server->command[1]);
        self::assertStringStartsWith('/', $server->command[0], 'bare php is the CLI binary');
        self::assertSame(90, $server->timeout);
    }

    #[Test]
    public function theDefaultTimeoutAppliesWhenTheRowHasNone(): void
    {
        $this->servers->add('bricklayer', true, 'composer', ['command' => ['/usr/bin/true']]);

        self::assertSame(45, ($this->resolver)('bricklayer')?->timeout);
    }

    #[Test]
    public function onlyAnEnabledPresentLocalServerResolves(): void
    {
        $this->servers->add('off', false);
        $this->servers->add('gone', true, 'composer', ['missing' => true]);
        $this->servers->add('remote', true, 'manual', ['transport' => 'http', 'url' => 'https://example.com/mcp', 'command' => []]);
        $this->servers->add('empty', true, 'composer', ['command' => []]);

        self::assertNull(($this->resolver)('off'));
        self::assertNull(($this->resolver)('gone'));
        self::assertNull(($this->resolver)('remote'));
        self::assertNull(($this->resolver)('empty'));
        self::assertNull(($this->resolver)('unknown'));
    }

    #[Test]
    public function knowsWhetherAnyLocalServerIsEnabled(): void
    {
        $this->servers->add('remote', true, 'manual', ['transport' => 'http', 'url' => 'https://example.com/mcp', 'command' => []]);
        $this->servers->add('off', false);
        self::assertFalse($this->resolver->anyEnabled());

        $this->servers->add('bricklayer', true);
        self::assertTrue($this->resolver->anyEnabled());
    }
}
