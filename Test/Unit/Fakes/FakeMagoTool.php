<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use MagoAssistant\Mago\Api\Tool\ToolInterface;

/**
 * Stands in for one of Mago's own read-only skills in a real ToolRegistry.
 */
final class FakeMagoTool implements ToolInterface
{
    public function __construct(private readonly string $name = 'sales_data')
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return 'Sales figures.';
    }

    public function getParameterSchema(): array
    {
        return ['type' => 'object'];
    }

    public function execute(array $params): array
    {
        return [];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function isReadOnlyAction(array $input): bool
    {
        return true;
    }

    public function getFieldClassification(string $action = ''): array
    {
        return [];
    }

    public function getMagentoAcl(array $input = []): string
    {
        return 'MagoAssistant_Mago::chat';
    }
}
