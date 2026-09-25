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
     * @param string $label The server's label, '' falls back to the server name
     * @param bool $outputPublic The server's output_public flag
     * @param array<string,array{0:string,1?:string}>|null $fieldClassification The tool's override from a
     *        module definition, or null for the server-wide rule
     */
    public function __construct(
        public readonly string $server,
        public readonly string $tool,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly string $mode,
        public readonly string $modeOrigin,
        public readonly bool $irreversible,
        public readonly bool $personalData,
        public readonly string $label = '',
        public readonly bool $outputPublic = false,
        public readonly ?array $fieldClassification = null
    ) {
    }

    public function label(): string
    {
        return $this->label !== '' ? $this->label : $this->server;
    }
}
