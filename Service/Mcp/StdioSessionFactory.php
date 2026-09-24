<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Mcp;

use Magento\Framework\App\Filesystem\DirectoryList;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as MagoConfig;
use MagoAssistant\Mago\Logger\DebugLogger;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Hand-written rather than generated so the scalar arguments are explicit and the client is testable.
 */
class StdioSessionFactory
{
    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly DebugLogger $debugLogger,
        private readonly MagoConfig $magoConfig,
        private readonly PhpExecutableFinder $phpFinder
    ) {
    }

    public function create(ServerConfig $server, int $timeoutSeconds): StdioSession
    {
        return new StdioSession($this->resolve($server), $this->directoryList->getRoot(), $timeoutSeconds, $this->debugLogger, $this->magoConfig);
    }

    /**
     * A bare "php" would be resolved through the web server's PATH, which php-fpm often clears and which
     * may hold a different PHP than the one running Magento; use the CLI binary Symfony finds instead.
     */
    public function resolve(ServerConfig $server): ServerConfig
    {
        if (($server->command[0] ?? null) !== 'php') {
            return $server;
        }
        $php = $this->phpFinder->find(false);
        if (!is_string($php) || $php === '') {
            return $server;
        }

        return new ServerConfig($server->name, [$php, ...array_slice($server->command, 1)], $server->env, $server->cwd);
    }
}
