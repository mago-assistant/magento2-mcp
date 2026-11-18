<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Url;

/**
 * The one rule every URL this module talks to follows: https://, or http:// on a local host only. The
 * admin form, .mcp.json discovery and OAuth metadata all ask here, so a bearer token or an
 * authorization code is never sent in clear across a network through any of the three doors.
 */
class EndpointRule
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];
    private const LOCAL_SUFFIXES = ['.local', '.internal', '.docker', '.test'];

    /**
     * Why the URL cannot be an endpoint, or null when it can.
     */
    public function error(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
        if ($host === '' || !in_array($scheme, ['https', 'http'], true)) {
            return 'The URL must be a full https:// address of the MCP endpoint.';
        }
        if ($scheme === 'http' && !$this->isLocal($host)) {
            return 'The URL must use https://; plain http:// is only allowed for localhost, 127.0.0.1, [::1] '
                . 'and hosts ending in .local, .internal, .docker or .test.';
        }

        return null;
    }

    private function isLocal(string $host): bool
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
}
