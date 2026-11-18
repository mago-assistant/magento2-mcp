<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Discovery;

use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mcp\Model\Config;

class ServerScanner
{
    /**
     * @param array<string,mixed> $sources SourceInterface items keyed module, composer, mcp_json; run in that order
     */
    public function __construct(
        private readonly array $sources,
        private readonly Config $config,
        private readonly ErrorLogger $errorLogger
    ) {
    }

    /**
     * Every discovered server, once per name: the first source to yield a name wins and a later
     * source's copy is logged and dropped.
     *
     * @return DiscoveredServer[]
     */
    public function scan(): array
    {
        $byName = [];
        foreach ($this->sources as $key => $source) {
            if ($key === 'mcp_json' && !$this->config->isScanMcpJsonEnabled()) {
                continue;
            }
            if (!$source instanceof SourceInterface) {
                continue;
            }
            foreach ($source->discover() as $server) {
                if (isset($byName[$server->name])) {
                    $this->errorLogger->addLog('MCP discovery duplicate dropped', [
                        'name' => $server->name,
                        'kept' => $byName[$server->name]->source,
                        'dropped' => $server->source,
                    ]);
                    continue;
                }
                $byName[$server->name] = $server;
            }
        }

        return array_values($byName);
    }
}
