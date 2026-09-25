<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Catalog;

/**
 * One MCP tool as the catalog sees it. Its mode is read or write, from the name classifier
 * ("classifier") or from the server's annotations tightening a read name to a write ("annotation").
 */
final class CatalogEntry
{
    public const ORIGIN_CLASSIFIER = 'classifier';
    public const ORIGIN_ANNOTATION = 'annotation';

    /**
     * @param array<string,mixed> $inputSchema
     */
    public function __construct(
        public readonly string $server,
        public readonly string $tool,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly string $mode,
        public readonly string $modeOrigin,
        public readonly bool $irreversible,
        public readonly bool $personalData
    ) {
    }
}
