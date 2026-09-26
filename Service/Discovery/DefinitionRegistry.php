<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Discovery;

/**
 * Every ServerDefinition registered in di.xml, by normalised name. The last definition for a name wins,
 * so a module can override another's definition by registering under the same item name.
 */
final class DefinitionRegistry
{
    /** @var array<string,ServerDefinition> */
    private array $byName = [];

    /**
     * @param array<int|string,mixed> $definitions ServerDefinition items from di.xml; anything else is ignored
     */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $definition) {
            if ($definition instanceof ServerDefinition) {
                $this->byName[$definition->name] = $definition;
            }
        }
    }

    /**
     * @return ServerDefinition[]
     */
    public function all(): array
    {
        return array_values($this->byName);
    }

    public function get(string $name): ?ServerDefinition
    {
        return $this->byName[DiscoveredServer::normaliseName($name)] ?? null;
    }
}
