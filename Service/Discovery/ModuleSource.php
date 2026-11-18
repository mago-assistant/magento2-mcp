<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Discovery;

/**
 * Servers any module ships as a ServerDefinition in di.xml.
 */
class ModuleSource implements SourceInterface
{
    public function __construct(private readonly DefinitionRegistry $definitions)
    {
    }

    public function discover(): array
    {
        return array_map(
            static fn (ServerDefinition $definition): DiscoveredServer => $definition->toDiscoveredServer(),
            $this->definitions->all()
        );
    }
}
