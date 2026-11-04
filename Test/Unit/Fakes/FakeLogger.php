<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use Psr\Log\AbstractLogger;

final class FakeLogger extends AbstractLogger
{
    /** @var array<int,array{0:string,1:string}> */
    public array $entries = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->entries[] = [(string)$level, (string)$message];
    }

    public function messages(): string
    {
        return implode("\n", array_column($this->entries, 1));
    }
}
