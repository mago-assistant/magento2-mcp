<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Api;

/**
 * @api
 */
interface AuthenticatorInterface
{
    /**
     * HTTP headers that authenticate a request to the MCP server for this admin user
     *
     * @param int|null $adminUserId Null when there is no user context (tool discovery, CLI)
     * @return array<string, string>
     */
    public function getHeaders(?int $adminUserId): array;

    /**
     * Called after the server answered 401; return true when new credentials were obtained
     * (e.g. an OAuth token refresh) so the request is retried once.
     */
    public function onUnauthorized(?int $adminUserId): bool;

    /**
     * Whether requests for this admin user can be authenticated at all (e.g. the user connected an
     * OAuth account). Tools of a server without credentials are not offered to that user.
     */
    public function hasCredentials(?int $adminUserId): bool;
}
