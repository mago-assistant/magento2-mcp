<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Worker;

/**
 * Whether this PHP may start processes. A function in disable_functions does not exist for PHP.
 */
class HostCheck
{
    public function canStartProcesses(): bool
    {
        return function_exists('proc_open') && function_exists('proc_get_status') && function_exists('proc_terminate');
    }
}
