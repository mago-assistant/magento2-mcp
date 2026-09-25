<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mcp\Api\ServerInterface;
use MagoAssistant\Mago\Logger\ErrorLogger;

/**
 * One McpTool per enabled MCP server, built from its cached tools/list.
 */
class ToolProvider
{
    private const CACHE_PREFIX = 'mago_mcp_tools_';
    private const CACHE_LIFETIME = 3600;
    // The chat panel lists tools on every admin page load: a down server must not stall each one.
    private const FAILURE_CACHE_LIFETIME = 300;

    /** @var array<string, ServerInterface> */
    private readonly array $servers;

    /** @var McpTool[]|null */
    private ?array $tools = null;

    /**
     * @param Client $client
     * @param CacheInterface $cache
     * @param Json $json
     * @param ErrorLogger $errorLogger
     * @param InstructionGate $instructionGate
     * @param ServerInterface[] $servers
     */
    public function __construct(
        private readonly Client $client,
        private readonly CacheInterface $cache,
        private readonly Json $json,
        private readonly ErrorLogger $errorLogger,
        private readonly InstructionGate $instructionGate,
        array $servers = []
    ) {
        $this->servers = $servers;
    }

    /**
     * Built once per request: the registry plugin asks for them several times per chat turn.
     *
     * @return McpTool[]
     */
    public function getTools(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }
        $tools = [];
        foreach ($this->servers as $server) {
            if (!$server->isEnabled()) {
                continue;
            }
            // Without credentials for the current user only a list cached for someone else can be shown.
            $definition = $this->getDefinition($server, false, $server->getAuthenticator()->hasCredentials(null));
            if ($definition['tools'] === []) {
                continue;
            }
            foreach ($definition['tools'] as $remoteTool) {
                $tools[] = new McpTool(
                    $server,
                    $this->client,
                    $remoteTool,
                    $this->instructionGate,
                    $definition['instructions']
                );
            }
        }

        return $this->tools = $tools;
    }

    /**
     * @return array<string, ServerInterface>
     */
    public function getServers(): array
    {
        return $this->servers;
    }

    /**
     * The server's allowed tools and instructions, from cache unless $refresh is set; $fetch false only reads the cache
     *
     * @return array{tools: array<int, array<string, mixed>>, instructions: string, error?: string}
     */
    public function getDefinition(ServerInterface $server, bool $refresh = false, bool $fetch = true): array
    {
        $cacheKey = self::CACHE_PREFIX . $server->getCode() . '_' . hash('sha256', $server->getUrl());
        if (!$refresh) {
            $cached = $this->cache->load($cacheKey);
            if (is_string($cached) && $cached !== '') {
                $definition = $this->json->unserialize($cached);
                if (is_array($definition) && is_array($definition['tools'] ?? null)) {
                    $normalized = [
                        'tools' => array_values(array_filter($definition['tools'], 'is_array')),
                        'instructions' => (string)($definition['instructions'] ?? ''),
                    ];
                    if (isset($definition['error'])) {
                        $normalized['error'] = (string)$definition['error'];
                    }
                    return $normalized;
                }
            }
        }

        if (!$fetch) {
            return ['tools' => [], 'instructions' => ''];
        }

        try {
            $listed = $this->client->listTools($server);
            $allowed = $server->getAllowedTools();
            $definition = [
                'tools' => array_values(array_filter(
                    $listed['tools'],
                    static fn (array $tool): bool => $allowed === [] || in_array($tool['name'], $allowed, true)
                )),
                'instructions' => $listed['instructions'],
            ];
            $lifetime = self::CACHE_LIFETIME;
        } catch (McpAuthenticationException $e) {
            // Not cached: whether it fails depends on the user, the next one may be connected.
            return ['tools' => [], 'instructions' => '', 'error' => $e->getMessage()];
        } catch (McpException $e) {
            $this->errorLogger->addLog('MCP', $e->getMessage());
            $definition = ['tools' => [], 'instructions' => '', 'error' => $e->getMessage()];
            $lifetime = self::FAILURE_CACHE_LIFETIME;
        }

        // Tagged as config so saving the server settings drops the stale tool list.
        $this->cache->save(
            (string)$this->json->serialize($definition),
            $cacheKey,
            [ConfigCache::CACHE_TAG],
            $lifetime
        );

        return $definition;
    }
}
