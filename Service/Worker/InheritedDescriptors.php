<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Worker;

/**
 * PHP marks no socket or file close-on-exec, so a process started with proc_open() inherits every
 * descriptor of its parent: the worker's listening socket and client connections, cron's lock files.
 * proc_open() places any descriptor number its spec names in the child, so pointing every open number
 * above stderr at /dev/null there closes the inherited copy. (A shell cannot do this portably: dash only
 * names descriptors 0 to 9.)
 */
final class InheritedDescriptors
{
    /**
     * @return array<int, array{0: string, 1: string, 2: string}> descriptor spec entries for proc_open()
     */
    public static function shadow(): array
    {
        $dir = is_dir('/proc/self/fd') ? '/proc/self/fd' : '/dev/fd';
        $spec = [];
        foreach (scandir($dir) ?: [] as $fd) {
            if (ctype_digit($fd) && (int)$fd > 2) {
                $spec[(int)$fd] = ['file', '/dev/null', 'r'];
            }
        }

        return $spec;
    }
}
