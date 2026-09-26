<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Admin;

use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use MagoAssistant\Mcp\Service\OAuth\ClientRepository;
use MagoAssistant\Mcp\Service\OAuth\TokenRepository;

/**
 * What the server edit page does. A manual row is the administrator's in full; a discovered row only in its
 * trust settings. OAuth credentials never outlive the server or the URL they were issued for.
 */
class ServerAdminService
{
    public function __construct(
        private readonly ServerRepositoryInterface $servers,
        private readonly TokenRepository $tokens,
        private readonly ClientRepository $clients,
        private readonly ToolCatalog $catalog
    ) {
    }

    /**
     * @param array<string,mixed> $post
     * @param string|null $existingName null to create
     * @return string the server's name
     * @throws \InvalidArgumentException with every reason the input was refused
     */
    public function save(array $post, ?string $existingName): string
    {
        $existing = $existingName === null ? null : $this->servers->getByName($existingName);
        if ($existingName !== null && $existing === null) {
            throw new \InvalidArgumentException(sprintf('No MCP server named "%s".', $existingName));
        }
        if ($existing !== null && $existing['source'] !== DiscoveredServer::SOURCE_MANUAL) {
            // Without the token key the repository leaves the token column untouched.
            unset($existing['bearer_token']);
            $this->servers->save(ServerInput::validateTrust($post) + $existing);
            $this->catalog->refresh((string)$existing['name']);

            return (string)$existing['name'];
        }

        $result = ServerInput::validate($post, $existing);
        $row = $result['row'];
        $errors = $result['errors'];
        if ($existing === null && $row['name'] !== '' && $this->servers->getByName((string)$row['name']) !== null) {
            $errors[] = sprintf('A server named "%s" already exists; choose another label.', $row['name']);
        }
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        if ($existing !== null) {
            $row['last_error'] = null;
            if ($existing['auth_type'] === ServerConfig::AUTH_OAUTH
                && ($existing['url'] !== $row['url'] || $row['auth_type'] !== ServerConfig::AUTH_OAUTH)
            ) {
                // A token is never sent to a host it was not issued for.
                $this->forgetCredentials((string)$row['name']);
            }
        }
        $this->servers->save($row);
        $this->catalog->refresh((string)$row['name']);

        return (string)$row['name'];
    }

    /**
     * @throws \InvalidArgumentException for an unknown or discovered row
     */
    public function delete(string $name): void
    {
        $existing = $this->servers->getByName($name);
        if ($existing === null) {
            throw new \InvalidArgumentException(sprintf('No MCP server named "%s".', $name));
        }
        if ($existing['source'] !== DiscoveredServer::SOURCE_MANUAL) {
            throw new \InvalidArgumentException(
                'Only a server added here can be deleted; disable a discovered one, or remove it at its source.'
            );
        }
        $this->forgetCredentials((string)$existing['name']);
        $this->servers->delete((string)$existing['name']);
        $this->catalog->refresh((string)$existing['name']);
    }

    private function forgetCredentials(string $name): void
    {
        $this->tokens->deleteForServer($name);
        $this->clients->delete($name);
    }
}
