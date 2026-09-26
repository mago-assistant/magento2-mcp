<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Auth;

use MagoAssistant\Mcp\Api\AuthenticatorInterface;
use MagoAssistant\Mcp\Service\Mcp\McpAuthenticationException;
use MagoAssistant\Mcp\Service\Mcp\McpException;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use MagoAssistant\Mcp\Service\OAuth\OAuthAuthenticatorFactory;

/**
 * The authenticator for a server's auth type, one instance per server per request so hasCredentials()
 * costs one lookup per server, not one per tool.
 */
class AuthenticatorResolver
{
    /** @var array<string,AuthenticatorInterface> */
    private array $memo = [];

    /**
     * @param OAuthAuthenticatorFactory|null $oauthFactory Null only where DI is absent (a bare test): an OAuth
     *        server then fails as an authentication failure rather than crashing
     */
    public function __construct(private readonly ?OAuthAuthenticatorFactory $oauthFactory = null)
    {
    }

    /**
     * @throws McpException
     */
    public function for(ServerConfig $server): AuthenticatorInterface
    {
        return $this->memo[$server->name] ??= $this->build($server);
    }

    /**
     * @throws McpException
     */
    private function build(ServerConfig $server): AuthenticatorInterface
    {
        return match ($server->authType) {
            ServerConfig::AUTH_NONE => new NoneAuthenticator(),
            ServerConfig::AUTH_BEARER => new BearerTokenAuthenticator($server->bearerToken),
            ServerConfig::AUTH_OAUTH => $this->oauthFactory?->create($server->name)
                ?? throw new McpAuthenticationException(sprintf(
                    'MCP server "%s" needs an OAuth connection, and none can be made here.',
                    $server->name
                )),
            default => throw new McpException(sprintf(
                'MCP server "%s" uses auth type "%s", which is not available.',
                $server->name,
                $server->authType
            )),
        };
    }
}
