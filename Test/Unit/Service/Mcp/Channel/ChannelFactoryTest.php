<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Mcp\Channel;

use Magento\Framework\App\DeploymentConfig;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Mcp\Channel\ChannelFactory;
use MagoAssistant\Mcp\Service\Mcp\Channel\ProcessChannel;
use MagoAssistant\Mcp\Service\Mcp\Channel\WorkerChannel;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChannelFactoryTest extends TestCase
{
    private function factory(string $sapi): ChannelFactory
    {
        return new ChannelFactory(new Config(new FakeScopeConfig()), $this->createStub(DeploymentConfig::class), $sapi);
    }

    #[Test]
    public function theCommandLineStartsServersItself(): void
    {
        self::assertInstanceOf(ProcessChannel::class, $this->factory('cli')->create());
        self::assertInstanceOf(ProcessChannel::class, $this->factory('phpdbg')->create());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function webSapis(): array
    {
        return ['php-fpm' => ['fpm-fcgi'], 'cgi' => ['cgi-fcgi'], 'FrankenPHP' => ['frankenphp'], 'mod_php' => ['apache2handler'], 'built-in server' => ['cli-server']];
    }

    #[Test]
    #[DataProvider('webSapis')]
    public function everyOtherSapiGoesThroughTheWorker(string $sapi): void
    {
        self::assertInstanceOf(WorkerChannel::class, $this->factory($sapi)->create());
    }

    #[Test]
    public function theSapiIsReadAtRunTimeNotFrozenByTheDiCompile(): void
    {
        // setup:di:compile runs on the command line and stores constructor defaults as values: a default of
        // PHP_SAPI would become "cli" for every web request of a compiled install.
        $parameter = (new \ReflectionMethod(ChannelFactory::class, '__construct'))->getParameters()[2];

        self::assertSame('sapi', $parameter->getName());
        self::assertNull($parameter->getDefaultValue());
        self::assertInstanceOf(ProcessChannel::class, (new ChannelFactory(new Config(new FakeScopeConfig()), $this->createStub(DeploymentConfig::class)))->create(), 'PHP_SAPI of this test run is cli');
    }

    #[Test]
    public function theWorkerAddressDefaultsToLoopback(): void
    {
        self::assertSame('127.0.0.1:8765', (new Config(new FakeScopeConfig()))->getWorkerAddress());
        self::assertSame('127.0.0.1:8765', (new Config(new FakeScopeConfig()))->getWorkerListen());
        self::assertSame('0.0.0.0:8765', (new Config(new FakeScopeConfig([Config::XML_PATH_WORKER_LISTEN => '0.0.0.0:8765'])))->getWorkerListen());
        self::assertSame('php-cli:8765', (new Config(new FakeScopeConfig([Config::XML_PATH_WORKER_ADDRESS => 'php-cli:8765'])))->getWorkerListen(), 'listen follows the address when unset');
    }
}
