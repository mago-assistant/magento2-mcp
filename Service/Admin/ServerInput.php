<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Admin;

use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;

/**
 * Form input for a remote server turned into a row, or a list of reasons it cannot be. Pure, so every rule
 * is tested without a request. The source owns a discovered row's connection details; the administrator
 * owns every row's trust settings (validateTrust()), and everything of a manual row (validate()).
 */
final class ServerInput
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];
    private const LOCAL_SUFFIXES = ['.local', '.internal', '.docker', '.test'];
    private const AUTH_TYPES = [ServerConfig::AUTH_NONE, ServerConfig::AUTH_BEARER, ServerConfig::AUTH_OAUTH];

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed>|null $existing the stored row when editing, null when creating
     * @return array{row: array<string,mixed>, errors: string[]}
     */
    public static function validate(array $post, ?array $existing): array
    {
        $errors = [];
        $label = trim((string)($post['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 128) {
            $errors[] = 'A label of 1 to 128 characters is required.';
        }
        // The name is derived once and never changes: it keys OAuth tokens, the cache and permission rows.
        $name = $existing !== null ? (string)$existing['name'] : DiscoveredServer::normaliseName($label);
        if ($name === '' && $label !== '') {
            $errors[] = 'The label must contain a letter or digit to name the server by.';
        }
        $url = trim((string)($post['url'] ?? ''));
        $urlError = self::urlError($url);
        if ($urlError !== null) {
            $errors[] = $urlError;
        }
        $authType = (string)($post['auth_type'] ?? ServerConfig::AUTH_NONE);
        if (!in_array($authType, self::AUTH_TYPES, true)) {
            $errors[] = 'Authentication must be none, bearer or oauth.';
        }
        $token = trim((string)($post['bearer_token'] ?? ''));
        if ($authType === ServerConfig::AUTH_BEARER && $token === '' && $existing === null) {
            $errors[] = 'A bearer token is required for bearer authentication.';
        }
        $timeout = trim((string)($post['timeout'] ?? ''));
        if ($timeout !== '' && (!ctype_digit($timeout) || (int)$timeout < 1 || (int)$timeout > 3600)) {
            $errors[] = 'The timeout must be empty or a number of seconds from 1 to 3600.';
        }
        $replaces = trim((string)($post['replaces_skill'] ?? ''));
        if ($replaces !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $replaces) !== 1) {
            $errors[] = 'Replaces skill must be a Mago skill name: lower-case letters, digits and _.';
        }

        $row = [
            'name' => $name,
            'label' => $label,
            'source' => DiscoveredServer::SOURCE_MANUAL,
            'transport' => ServerConfig::TRANSPORT_HTTP,
            'command' => [],
            'env' => [],
            'cwd' => null,
            'url' => $url,
            'auth_type' => $authType,
            'allowed_tools' => self::lines((string)($post['allowed_tools'] ?? '')),
            'timeout' => $timeout === '' ? null : (int)$timeout,
            'replaces_skill' => $replaces,
            'enabled' => self::checked($post, 'enabled'),
            'missing' => false,
        ] + self::validateTrust($post, $existing);
        // Blank keeps the stored token: the key is left out, so the repository does not touch the column.
        if ($token !== '') {
            $row['bearer_token'] = $token;
        }

        return ['row' => $row, 'errors' => $errors];
    }

    /**
     * The settings an administrator owns on any row, whatever its source.
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed>|null $existing
     * @return array{read_only: bool, output_public: bool}
     */
    public static function validateTrust(array $post, ?array $existing): array
    {
        return ['read_only' => self::checked($post, 'read_only'), 'output_public' => self::checked($post, 'output_public')];
    }

    private static function urlError(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
        if ($host === '' || !in_array($scheme, ['https', 'http'], true)) {
            return 'The URL must be a full https:// address of the MCP endpoint.';
        }
        if ($scheme === 'http' && !self::isLocal($host)) {
            return 'The URL must use https://; plain http:// is only allowed for localhost, 127.0.0.1, [::1] '
                . 'and hosts ending in .local, .internal, .docker or .test.';
        }

        return null;
    }

    private static function isLocal(string $host): bool
    {
        if (in_array($host, self::LOCAL_HOSTS, true)) {
            return true;
        }
        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $post
     */
    private static function checked(array $post, string $key): bool
    {
        return in_array((string)($post[$key] ?? ''), ['1', 'on', 'true'], true);
    }

    /**
     * @return string[]
     */
    private static function lines(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R/', $text) ?: []),
            static fn (string $line): bool => $line !== ''
        ));
    }
}
