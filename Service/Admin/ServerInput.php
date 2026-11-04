<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Admin;

use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use MagoAssistant\Mcp\Service\Url\EndpointRule;

/**
 * Form input for a remote server turned into a row, or a list of reasons it cannot be. Pure, so every rule
 * is tested without a request. The source owns a discovered row's connection details; the administrator
 * owns every row's trust settings (validateTrust()), and everything of a manual row (validate()).
 */
final class ServerInput
{
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
        if ($authType === ServerConfig::AUTH_BEARER && $token === '' && !self::keepsStoredToken($existing, $url)) {
            // A stored token is kept only for the host it was entered for: pointing the row elsewhere must
            // not hand a write-only secret to that host.
            $errors[] = 'Enter the bearer token: it is required for a new server, a new host, or a switch to bearer.';
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
            'timeout' => $timeout === '' ? null : (int)$timeout,
            'replaces_skill' => $replaces,
            'enabled' => self::checked($post, 'enabled'),
            'missing' => false,
        ] + self::validateTrust($post);
        // Blank keeps the stored token: the key is left out, so the repository does not touch the column.
        if ($token !== '') {
            $row['bearer_token'] = $token;
        }

        return ['row' => $row, 'errors' => $errors];
    }

    /**
     * The settings an administrator owns on any row, whatever its source: the trust flags and the list of
     * tools the server may expose at all, so a discovered server's code runner can be kept out of the
     * catalog without disabling the server or touching every admin's permissions. The form shows the
     * privacy filter, ticked by default; the row stores its inverse, output_public.
     *
     * @param array<string,mixed> $post
     * @return array{read_only: bool, output_public: bool, allowed_tools: string[]}
     */
    public static function validateTrust(array $post): array
    {
        return [
            'read_only' => self::checked($post, 'read_only'),
            'output_public' => !self::checked($post, 'privacy_filter'),
            'allowed_tools' => self::names($post['allowed_tools'] ?? ''),
        ];
    }

    /**
     * The allowed-tool names as the edit page posts them (one checkbox per scanned tool, so an array)
     * or as a text field lists them (one per line).
     *
     * @return string[]
     */
    private static function names(mixed $value): array
    {
        if (is_array($value)) {
            $names = [];
            foreach ($value as $name) {
                if (is_scalar($name) && trim((string)$name) !== '') {
                    $names[] = trim((string)$name);
                }
            }

            return array_values(array_unique($names));
        }

        return self::lines((string)$value);
    }

    /**
     * @param array<string,mixed>|null $existing
     */
    private static function keepsStoredToken(?array $existing, string $url): bool
    {
        if ($existing === null || (string)($existing['bearer_token'] ?? '') === '') {
            return false;
        }

        return self::origin((string)($existing['url'] ?? '')) === self::origin($url);
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        return is_array($parts)
            ? strtolower((string)($parts['scheme'] ?? '')) . '://' . strtolower((string)($parts['host'] ?? ''))
                . (isset($parts['port']) ? ':' . $parts['port'] : '')
            : '';
    }

    /**
     * The module-wide endpoint rule (Service\Url\EndpointRule); this class is a pure static validator by
     * design, so the dependency-free rule is instantiated here rather than injected.
     */
    private static function urlError(string $url): ?string
    {
        return (new EndpointRule())->error($url);
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
