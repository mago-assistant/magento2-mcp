<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Worker;

use Magento\Framework\App\Filesystem\DirectoryList;

// phpcs:disable Magento2.Functions.DiscouragedFunction

/**
 * The running worker's pid in var/mago-mcp-worker.pid, so cron can end a worker it can no longer talk to
 * (one started under an earlier crypt key or protocol version).
 */
class WorkerProcess
{
    private const FILE = 'mago-mcp-worker.pid';
    private const COMMAND = 'mago:mcp:serve';

    public function __construct(private readonly DirectoryList $directoryList)
    {
    }

    public function record(int $pid): void
    {
        $file = $this->file();
        if (is_writable(dirname($file))) {
            file_put_contents($file, $pid . "\n");
        }
    }

    /**
     * Remove the record if it is this worker's.
     */
    public function clear(int $pid): void
    {
        if ($this->recorded() === $pid && is_file($this->file())) {
            unlink($this->file());
        }
    }

    /**
     * Ask the recorded worker to stop (SIGTERM; it ends its calls and exits). Only a process whose
     * command line is the worker's is signalled, so a pid reused by another program is left alone.
     */
    public function kill(): bool
    {
        $pid = $this->recorded();
        if ($pid === null || !function_exists('posix_kill')) {
            return false;
        }
        $cmdline = '/proc/' . $pid . '/cmdline';
        if (is_readable($cmdline)) {
            if (!str_contains(str_replace("\0", ' ', (string)file_get_contents($cmdline)), self::COMMAND)) {
                return false;
            }
        } elseif (!posix_kill($pid, 0)) {
            return false;
        }

        return posix_kill($pid, 15);
    }

    private function recorded(): ?int
    {
        $value = is_readable($this->file()) ? trim((string)file_get_contents($this->file())) : '';

        return ctype_digit($value) && (int)$value > 0 ? (int)$value : null;
    }

    private function file(): string
    {
        return $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/' . self::FILE;
    }
}
