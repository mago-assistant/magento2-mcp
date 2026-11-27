<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Worker;

/**
 * Proof that a connection to the MCP worker comes from the same Magento install.
 *
 * Both sides derive the key from the install's crypt key (app/etc/env.php), so nothing is configured or
 * stored. The worker sends a fresh nonce; the client signs nonce, operation and server name with it. A
 * captured line is useless on another connection (other nonce) and cannot be turned into another request
 * (other operation or server). No Magento dependency, so the relay can use it on its own.
 */
final class Handshake
{
    public const PROTOCOL_VERSION = 1;

    private const KEY_LABEL = 'mago-mcp-worker:v1';

    /**
     * The worker key for a crypt key value. A rotated crypt key lists every key on its own line; the
     * current one, the last line, is used.
     */
    public static function key(string $cryptKey): string
    {
        $lines = preg_split('/\R/', trim($cryptKey)) ?: [''];

        return hash_hmac('sha256', self::KEY_LABEL, trim((string)end($lines)));
    }

    public static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function sign(string $key, string $nonce, string $op, string $server): string
    {
        return hash_hmac('sha256', $nonce . "\n" . $op . "\n" . $server, $key);
    }

    public static function verify(string $key, string $nonce, string $op, string $server, string $auth): bool
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $auth)) {
            return false;
        }

        return hash_equals(self::sign($key, $nonce, $op, $server), $auth);
    }
}
