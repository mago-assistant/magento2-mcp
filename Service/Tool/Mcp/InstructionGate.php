<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Tool\Mcp;

/**
 * Hands out each server's instructions once per request, so using three tools of one server does not
 * put the same instructions into the conversation three times.
 */
class InstructionGate
{
    /** @var array<string, true> */
    private array $claimed = [];

    public function claim(string $serverCode): bool
    {
        if (isset($this->claimed[$serverCode])) {
            return false;
        }
        $this->claimed[$serverCode] = true;

        return true;
    }
}
