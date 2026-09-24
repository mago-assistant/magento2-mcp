<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Catalog;

use Magento\Framework\Cache\FrontendInterface;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mcp\Api\McpClientInterface;
use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Model\Cache\Type\McpTools;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Mcp\McpException;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;

/**
 * What the model may call: every enabled server's tools/list, cached, with a read/write/disabled mode
 * resolved per tool from the admin's overrides, the shipped defaults, the name classifier, and finally
 * the server's own annotations, which may only make a tool stricter.
 */
class ToolCatalog
{
    private const CACHE_PREFIX = 'mago_mcp_tools_';
    private const FAILURE_LIFETIME = 60;

    /** @var array<string,array{tools: array<int,array<string,mixed>>, instructions: string}>|null per-request memo */
    private ?array $memo = null;

    /** @var array<int,array<string,mixed>>|null enabled rows, read once per request */
    private ?array $rowsMemo = null;

    /** @var array<string,CatalogEntry[]> built entries per server name, once per request */
    private array $entriesMemo = [];

    public function __construct(
        private readonly ServerRepositoryInterface $servers,
        private readonly McpClientInterface $client,
        private readonly FrontendInterface $cache,
        private readonly Config $config,
        private readonly ModeClassifier $classifier,
        private readonly DefaultOverrides $defaults,
        private readonly ErrorLogger $errorLogger
    ) {
    }

    /**
     * @return CatalogEntry[]
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->enabledRows() as $row) {
            foreach ($this->buildEntries($row) as $entry) {
                if ($entry->isCallable()) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * Every tool of one server, disabled ones included, whether or not the server is enabled.
     *
     * @return CatalogEntry[]
     */
    public function entriesForServer(string $name): array
    {
        $row = $this->servers->getByName($name);

        return $row === null ? [] : $this->buildEntries($row);
    }

    public function find(string $action): ?CatalogEntry
    {
        foreach ($this->entries() as $entry) {
            if ($entry->action() === $action) {
                return $entry;
            }
        }

        return null;
    }

    public function serverConfig(string $name): ?ServerConfig
    {
        $row = $this->servers->getByName($name);

        return $row !== null && $row['enabled'] ? ServerConfig::fromRow($row) : null;
    }

    /**
     * @return array<string,int> enabled server name => number of callable tools
     */
    public function serverCounts(): array
    {
        $counts = [];
        foreach ($this->enabledRows() as $row) {
            $callable = array_filter($this->buildEntries($row), static fn (CatalogEntry $e): bool => $e->isCallable());
            $counts[(string)$row['name']] = count($callable);
        }

        return $counts;
    }

    /**
     * @return array<string,string> enabled server name => instructions its initialize result carried
     */
    public function serverInstructions(): array
    {
        $instructions = [];
        foreach ($this->enabledRows() as $row) {
            $text = $this->fetched($row)['instructions'];
            if ($text !== '') {
                $instructions[(string)$row['name']] = $text;
            }
        }

        return $instructions;
    }

    public function refresh(?string $name = null): void
    {
        $this->memo = null;
        $this->rowsMemo = null;
        $this->entriesMemo = [];
        if ($name === null) {
            $this->cache->clean(\Zend_Cache::CLEANING_MODE_MATCHING_TAG, [McpTools::CACHE_TAG]);

            return;
        }
        $this->cache->remove($this->cacheId($name));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function enabledRows(): array
    {
        if ($this->rowsMemo === null) {
            $this->rowsMemo = $this->config->isEnabled() ? $this->servers->getEnabled() : [];
        }

        return $this->rowsMemo;
    }

    /**
     * @param array<string,mixed> $row
     * @return CatalogEntry[]
     */
    private function buildEntries(array $row): array
    {
        $name = (string)$row['name'];
        if (isset($this->entriesMemo[$name])) {
            return $this->entriesMemo[$name];
        }
        $overrides = is_array($row['tool_overrides'] ?? null) ? $row['tool_overrides'] : [];
        $entries = [];
        foreach ($this->fetched($row)['tools'] as $tool) {
            $toolName = (string)$tool['name'];
            $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];
            [$mode, $origin] = $this->resolveMode($name, $toolName, $overrides, $annotations);
            $entries[] = new CatalogEntry(
                $name,
                $toolName,
                is_string($tool['title'] ?? null) ? $tool['title'] : '',
                (string)($tool['description'] ?? ''),
                $this->normaliseSchema($tool['inputSchema'] ?? null),
                $mode,
                $origin,
                $this->classifier->isIrreversible($toolName) || ($annotations['destructiveHint'] ?? false) === true,
                $this->defaults->isPersonalData($name, $toolName)
            );
        }

        return $this->entriesMemo[$name] = $entries;
    }

    /**
     * @param array<string,string> $overrides
     * @param array<string,mixed> $annotations
     * @return array{0:string,1:string}
     */
    private function resolveMode(string $server, string $tool, array $overrides, array $annotations): array
    {
        $override = $overrides[$tool] ?? null;
        if (is_string($override) && $this->classifier->isValidMode($override)) {
            return [$override, CatalogEntry::ORIGIN_OVERRIDE];
        }
        $default = $this->defaults->modeFor($server, $tool);
        if ($default !== null) {
            return [$default, CatalogEntry::ORIGIN_DEFAULT];
        }
        $mode = $this->classifier->classify($tool);
        if ($mode === ModeClassifier::READ && ($annotations['readOnlyHint'] ?? null) === false) {
            return [ModeClassifier::WRITE, CatalogEntry::ORIGIN_ANNOTATION];
        }

        return [$mode, CatalogEntry::ORIGIN_CLASSIFIER];
    }

    /**
     * JSON decoding turns "properties": {} into an empty PHP array, which re-encodes as [] and is not a
     * valid JSON Schema object. Restore the object so the model and the cache see the schema as sent.
     *
     * @return array<string,mixed>
     */
    private function normaliseSchema(mixed $schema): array
    {
        $schema = is_array($schema) ? $schema : ['type' => 'object'];
        if (array_key_exists('properties', $schema) && $schema['properties'] === []) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    /**
     * The server's tools/list and initialize extras, from the per-request memo, then the cache, then
     * the server. A failure caches nothing and is recorded on the row so the admin page can show it;
     * other servers are unaffected.
     *
     * @param array<string,mixed> $row
     * @return array{tools: array<int,array<string,mixed>>, instructions: string}
     */
    private function fetched(array $row): array
    {
        $name = (string)$row['name'];
        if (isset($this->memo[$name])) {
            return $this->memo[$name];
        }
        $cached = $this->cache->load($this->cacheId($name));
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && array_key_exists('error', $decoded)) {
                // A recent failure: do not spawn the server again for every request while it is down.
                return $this->memo[$name] = ['tools' => [], 'instructions' => ''];
            }
            if (is_array($decoded) && isset($decoded['tools'])) {
                return $this->memo[$name] = [
                    'tools' => $decoded['tools'],
                    'instructions' => (string)($decoded['instructions'] ?? ''),
                ];
            }
        }
        try {
            $result = $this->client->listTools(ServerConfig::fromRow($row), $this->config->getProcessTimeout());
        } catch (McpException $e) {
            $this->errorLogger->addLog('MCP tools/list', ['server' => $name, 'error' => $e->getMessage()]);
            $this->servers->setLastError($name, $e->getMessage());
            $this->cache->save(
                json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $this->cacheId($name),
                [McpTools::CACHE_TAG],
                self::FAILURE_LIFETIME
            );

            return $this->memo[$name] = ['tools' => [], 'instructions' => ''];
        }
        $fetched = ['tools' => $result['tools'], 'instructions' => $result['instructions']];
        $this->cache->save(
            json_encode($fetched, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $this->cacheId($name),
            [McpTools::CACHE_TAG],
            $this->config->getCacheLifetime()
        );
        if (($row['last_error'] ?? null) !== null) {
            $this->servers->setLastError($name, null);
        }

        return $this->memo[$name] = $fetched;
    }

    /**
     * Magento's cache frontend accepts only [A-Za-z0-9_] in ids; server names may contain "-".
     */
    private function cacheId(string $name): string
    {
        return self::CACHE_PREFIX . preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
    }
}
