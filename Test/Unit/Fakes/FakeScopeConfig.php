<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use Magento\Framework\App\Config\ScopeConfigInterface;

final class FakeScopeConfig implements ScopeConfigInterface
{
    /**
     * @param array<string,mixed> $values config path => value
     */
    public function __construct(private readonly array $values = [])
    {
    }

    public function getValue($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return $this->values[$path] ?? null;
    }

    public function isSetFlag($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return (bool)($this->values[$path] ?? false);
    }
}
