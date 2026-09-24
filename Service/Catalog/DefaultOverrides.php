<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Catalog;

/**
 * Shipped per-server defaults, applied when the administrator has not set a tool's mode.
 *
 * The profiles are data supplied by di.xml, keyed by normalised server name, so the addon's core knows
 * no particular server: the addon ships a profile for Bricklayer in its own etc/di.xml, and any module
 * can add one for its server the same way. A profile can disable tools (execution surfaces that would
 * turn the chat into a remote shell), force tools to read (a SELECT-only query tool the name classifier
 * would call a write), and flag tool-name prefixes that return personal data.
 */
class DefaultOverrides
{
    /**
     * @param array<string, string[]> $disabledTools server name => tool names shipped disabled
     * @param array<string, string[]> $readTools server name => tool names shipped as reads
     * @param array<string, string[]> $personalDataPrefixes server name => tool-name prefixes returning personal data
     */
    public function __construct(
        private readonly array $disabledTools = [],
        private readonly array $readTools = [],
        private readonly array $personalDataPrefixes = []
    ) {
    }

    public function modeFor(string $server, string $tool): ?string
    {
        if (in_array($tool, $this->disabledTools[$server] ?? [], true)) {
            return ModeClassifier::DISABLED;
        }
        if (in_array($tool, $this->readTools[$server] ?? [], true)) {
            return ModeClassifier::READ;
        }

        return null;
    }

    public function isPersonalData(string $server, string $tool): bool
    {
        foreach ($this->personalDataPrefixes[$server] ?? [] as $prefix) {
            if (str_starts_with($tool, (string)$prefix)) {
                return true;
            }
        }

        return false;
    }
}
