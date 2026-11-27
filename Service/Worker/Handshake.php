<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Worker;

/**
 * Proof, both ways, that the MCP worker and its client belong to the same Magento install.
 *
 * Both sides derive their key from the install's crypt key (app/etc/env.php), so nothing is configured or
 * stored. The worker sends a fresh nonce; the client sends its own nonce and signs both nonces with the
 * operation, the server name and the timeout. The worker signs its answer with both nonces, so a process
 * that took the worker's port cannot pass itself off as the worker, and a captured line is useless on
 * another connection. No Magento dependency, so the relay can use it on its own.
 */
final class Handshake
{
    public const PROTOCOL_VERSION = 2;

    private const KEY_LABEL = 'mago-mcp-worker:v2';

    /**
     * The worker keys for a crypt key value, one per line; a rotated crypt key lists the current key last.
     *
     * @return string[]
     */
    public static function keys(string $cryptKey): array
    {
        $keys = [];
        foreach (preg_split('/\R/', trim($cryptKey)) ?: [] as $line) {
            if (trim($line) !== '') {
                $keys[] = hash_hmac('sha256', self::KEY_LABEL, trim($line));
            }
        }

        return $keys;
    }

    /**
     * The key a client signs with: the one of the current (last) crypt key; '' when there is none.
     */
    public static function key(string $cryptKey): string
    {
        $keys = self::keys($cryptKey);

        return $keys === [] ? '' : (string)end($keys);
    }

    public static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isNonce(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{32}$/', $value) === 1;
    }

    public static function signRequest(
        string $key,
        string $workerNonce,
        string $clientNonce,
        string $op,
        string $server,
        int $timeout
    ): string {
        return hash_hmac('sha256', implode("\n", ['request', $workerNonce, $clientNonce, $op, $server, (string)$timeout]), $key);
    }

    /**
     * @param string[] $keys
     * @return string|null the key the request was signed with, or null
     */
    public static function verifyRequest(
        array $keys,
        string $workerNonce,
        string $clientNonce,
        string $op,
        string $server,
        int $timeout,
        string $auth
    ): ?string {
        if (!preg_match('/^[0-9a-f]{64}$/', $auth)) {
            return null;
        }
        foreach ($keys as $key) {
            if ($key !== '' && hash_equals(self::signRequest($key, $workerNonce, $clientNonce, $op, $server, $timeout), $auth)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The worker's answer, with its proof added.
     *
     * @param array<string,mixed> $answer
     * @return array<string,mixed>
     */
    public static function signAnswer(string $key, string $workerNonce, string $clientNonce, array $answer): array
    {
        unset($answer['proof']);

        return $answer + ['proof' => hash_hmac('sha256', self::canonical($workerNonce, $clientNonce, $answer), $key)];
    }

    /**
     * @param array<string,mixed> $answer as received, with its proof
     */
    public static function verifyAnswer(string $key, string $workerNonce, string $clientNonce, array $answer): bool
    {
        $proof = $answer['proof'] ?? null;
        unset($answer['proof']);
        if ($key === '' || !is_string($proof) || !preg_match('/^[0-9a-f]{64}$/', $proof)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', self::canonical($workerNonce, $clientNonce, $answer), $key), $proof);
    }

    /**
     * @param array<string,mixed> $answer
     */
    private static function canonical(string $workerNonce, string $clientNonce, array $answer): string
    {
        ksort($answer);

        return implode("\n", ['answer', $workerNonce, $clientNonce, (string)json_encode(
            $answer,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        )]);
    }
}
