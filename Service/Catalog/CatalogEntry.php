<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Catalog;

final class CatalogEntry
{
    public const ORIGIN_OVERRIDE = 'override';
    public const ORIGIN_DEFAULT = 'default';
    public const ORIGIN_CLASSIFIER = 'classifier';
    public const ORIGIN_ANNOTATION = 'annotation';

    /**
     * @param array<string,mixed> $inputSchema
     */
    public function __construct(
        public readonly string $server,
        public readonly string $tool,
        public readonly string $title,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly string $mode,
        public readonly string $modeOrigin,
        public readonly bool $irreversible,
        public readonly bool $personalData
    ) {
    }

    public function action(): string
    {
        return $this->server . ':' . $this->tool;
    }

    public function isCallable(): bool
    {
        return $this->mode !== ModeClassifier::DISABLED;
    }
}
