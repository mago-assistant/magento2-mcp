<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Worker;

use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use MagoAssistant\Mcp\Service\Mcp\StdioSessionFactory;

/**
 * What the worker may start: a stored row by name, only when it is an enabled, present local server.
 * The command always comes from the database, never from the client.
 */
class ServerResolver
{
    public function __construct(
        private readonly ServerRepositoryInterface $servers,
        private readonly Config $config,
        private readonly StdioSessionFactory $sessions
    ) {
    }

    public function __invoke(string $name): ?ServerConfig
    {
        $row = $this->servers->getByName($name);
        if ($row === null || !$this->isRunnable($row)) {
            return null;
        }

        return $this->sessions->resolve(ServerConfig::fromRow($row, $this->config->getProcessTimeout()));
    }

    public function anyEnabled(): bool
    {
        foreach ($this->servers->getEnabled() as $row) {
            if ($this->isRunnable($row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isRunnable(array $row): bool
    {
        return ($row['enabled'] ?? false)
            && !($row['missing'] ?? false)
            && ($row['transport'] ?? ServerConfig::TRANSPORT_STDIO) === ServerConfig::TRANSPORT_STDIO
            && is_array($row['command'] ?? null)
            && $row['command'] !== [];
    }
}
