<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Discovery;

use MagoAssistant\Mcp\Model\Config;

class ServerScanner
{
    public function __construct(
        private readonly SourceInterface $composerSource,
        private readonly SourceInterface $mcpJsonSource,
        private readonly Config $config
    ) {
    }

    /**
     * Every discovered server, keyed once by name. Composer entries win over .mcp.json entries.
     *
     * @return DiscoveredServer[]
     */
    public function scan(): array
    {
        $found = $this->composerSource->discover();
        if ($this->config->isScanMcpJsonEnabled()) {
            $found = [...$found, ...$this->mcpJsonSource->discover()];
        }
        $byName = [];
        foreach ($found as $server) {
            if (!isset($byName[$server->name])) {
                $byName[$server->name] = $server;
            }
        }

        return array_values($byName);
    }
}
