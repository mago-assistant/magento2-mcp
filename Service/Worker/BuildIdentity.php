<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Worker;

// phpcs:disable Magento2.Functions.DiscouragedFunction

/**
 * What a Magento install was built from, so a worker started before a deploy is replaced after it, also
 * when the deploy happened in place (git pull, setup:upgrade, a static content deploy) in the same directory.
 */
final class BuildIdentity
{
    public static function of(string $root): string
    {
        $real = realpath($root) ?: $root;
        clearstatcache();
        $parts = [
            $real,
            self::read($real . '/pub/static/deployed_version.txt'),
            self::mtime($real . '/app/etc/config.php'),
            self::mtime($real . '/generated/metadata/global.php'),
            // This module's worker code: an upgrade of the addon itself replaces the worker.
            self::mtime(__DIR__ . '/Relay.php'),
            self::mtime(__DIR__ . '/Handshake.php'),
        ];

        return substr(hash('sha256', implode("\n", $parts)), 0, 16);
    }

    private static function mtime(string $file): string
    {
        return is_file($file) ? (string)filemtime($file) : '';
    }

    private static function read(string $file): string
    {
        return is_readable($file) ? trim((string)file_get_contents($file)) : '';
    }
}
