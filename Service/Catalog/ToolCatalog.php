<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Catalog;

use Magento\Framework\Cache\FrontendInterface;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Model\Cache\Type\McpTools;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Discovery\DefinitionRegistry;
use MagoAssistant\Mcp\Service\Mcp\McpAuthenticationException;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use MagoAssistant\Mcp\Service\Transport\TransportResolver;

/**
 * What the model may call: every enabled server's tools/list, cached, with a read/write mode from the
 * name classifier tightened by the server's own annotations, as Mago's own skills carry their type in
 * code.
 */
class ToolCatalog
{
    private const CACHE_PREFIX = 'mago_mcp_tools_';
    private const FAILURE_LIFETIME = 300;

    /** @var array<string,array{tools: array<int,array<string,mixed>>, instructions: string}>|null per-request memo */
    private ?array $memo = null;

    /** @var array<int,array<string,mixed>>|null enabled rows, read once per request */
    private ?array $rowsMemo = null;

    /** @var array<string,CatalogEntry[]> built entries per server name, once per request */
    private array $entriesMemo = [];

    public function __construct(
        private readonly ServerRepositoryInterface $servers,
        private readonly TransportResolver $transports,
        private readonly FrontendInterface $cache,
        private readonly Config $config,
        private readonly ModeClassifier $classifier,
        private readonly ErrorLogger $errorLogger,
        private readonly DefinitionRegistry $definitions
    ) {
    }

    /**
     * Every tool of every enabled server.
     *
     * @return CatalogEntry[]
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->enabledRows() as $row) {
            foreach ($this->buildEntries($row) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Every tool of one server, whether or not the server is enabled.
     *
     * @return CatalogEntry[]
     */
    public function entriesForServer(string $name): array
    {
        $row = $this->servers->getByName($name);

        return $row === null ? [] : $this->buildEntries($row);
    }

    public function serverConfig(string $name): ?ServerConfig
    {
        $row = $this->servers->getByName($name);

        return $row !== null && $row['enabled']
            ? ServerConfig::fromRow($row, $this->config->getProcessTimeout(), $this->definitions->get($name))
            : null;
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
        $entries = [];
        $definition = $this->definitions->get($name);
        // A token-cost filter, not a permission: a name not on the list is not a skill at all.
        $allowed = array_map('strval', is_array($row['allowed_tools'] ?? null) ? $row['allowed_tools'] : []);
        foreach ($this->fetched($row)['tools'] as $tool) {
            $toolName = (string)$tool['name'];
            if ($allowed !== [] && !in_array($toolName, $allowed, true)) {
                continue;
            }
            $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];
            [$mode, $origin] = $this->modeOf($toolName, $annotations);
            // A read tool is never irreversible, whatever IRREVERSIBLE_WORDS or destructiveHint says.
            $irreversible = $mode === ModeClassifier::WRITE
                && ($this->classifier->isIrreversible($toolName) || ($annotations['destructiveHint'] ?? false) === true);
            $entries[] = new CatalogEntry(
                $name,
                $toolName,
                (string)($tool['description'] ?? ''),
                $this->normaliseSchema($tool['inputSchema'] ?? null),
                $mode,
                $origin,
                $irreversible,
                $this->classifier->isPersonalData($toolName),
                (string)($row['label'] ?? ''),
                (bool)($row['output_public'] ?? false),
                $definition?->fieldClassificationOverrides[$toolName] ?? null
            );
        }

        return $this->entriesMemo[$name] = $entries;
    }

    /**
     * The name classifier's read or write; readOnlyHint false or destructiveHint true turn a read into a
     * write, and nothing turns a write into a read.
     *
     * @param string $tool
     * @param array<string,mixed> $annotations
     * @return array{0:string,1:string} mode and where it came from
     */
    private function modeOf(string $tool, array $annotations): array
    {
        $mode = $this->classifier->classify($tool);
        if ($mode === ModeClassifier::READ
            && (($annotations['readOnlyHint'] ?? null) === false || ($annotations['destructiveHint'] ?? null) === true)
        ) {
            return [ModeClassifier::WRITE, CatalogEntry::ORIGIN_ANNOTATION];
        }

        return [$mode, CatalogEntry::ORIGIN_CLASSIFIER];
    }

    /**
     * JSON decoding turns every {} into an empty PHP array, which re-encodes as [] and is not a valid
     * JSON Schema object (providers reject "items": [] or "properties": []). Restore the object wherever
     * JSON Schema expects one, at any depth: properties and patternProperties themselves and each entry,
     * $defs/definitions entries, items, additionalProperties and the subschemas of not/anyOf/oneOf/allOf.
     * Lists such as required, enum or a type array stay arrays.
     *
     * @return array<string,mixed>
     */
    private function normaliseSchema(mixed $schema): array
    {
        return $this->normaliseNode(is_array($schema) ? $schema : ['type' => 'object']);
    }

    /**
     * Restore the empty objects of one schema node and walk its subschemas.
     *
     * @param array<mixed> $node
     * @return array<mixed>
     */
    private function normaliseNode(array $node): array
    {
        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            switch ((string)$key) {
                case 'properties':
                case 'patternProperties':
                case '$defs':
                case 'definitions':
                    $node[$key] = $value === [] ? new \stdClass() : array_map([$this, 'normaliseSubschema'], $value);
                    break;
                case 'items':
                    // An old-style tuple is a list of schemas; otherwise it is one schema.
                    $node[$key] = $value !== [] && array_is_list($value)
                        ? array_map([$this, 'normaliseSubschema'], $value)
                        : $this->normaliseSubschema($value);
                    break;
                case 'additionalProperties':
                case 'not':
                    $node[$key] = $this->normaliseSubschema($value);
                    break;
                case 'anyOf':
                case 'oneOf':
                case 'allOf':
                    $node[$key] = array_map([$this, 'normaliseSubschema'], $value);
                    break;
            }
        }

        return $node;
    }

    /**
     * One subschema: an empty array is the object {}, any other array is walked, anything else is kept.
     *
     * @param mixed $schema a subschema, possibly true or false
     * @return mixed
     */
    private function normaliseSubschema(mixed $schema): mixed
    {
        if ($schema === []) {
            return new \stdClass();
        }

        return is_array($schema) ? $this->normaliseNode($schema) : $schema;
    }

    /**
     * The server's tools/list and initialize extras, from the per-request memo, then the cache, then
     * the server. A failure is cached for five minutes and recorded on the row so the admin page can
     * show it; other servers are unaffected.
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
                // A recent failure: the chat panel lists tools on every admin page load, so a down server
                // costs one attempt per five minutes, never one per page.
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
            $server = ServerConfig::fromRow($row, $this->config->getProcessTimeout(), $this->definitions->get($name));
            $result = $this->transports->for($server)->listTools($server);
        } catch (\Throwable $e) {
            // Any failure, including one a transport forgot to wrap, is this server's alone.
            $this->errorLogger->addLog('MCP tools/list', ['server' => $name, 'error' => $e->getMessage()]);
            if ($e instanceof McpAuthenticationException && $server->authType === ServerConfig::AUTH_OAUTH) {
                // Per-admin credentials: not cached and not on the row, the next admin may be the
                // connected one. A rejected static token is an ordinary failure, cached and shown.
                return $this->memo[$name] = ['tools' => [], 'instructions' => ''];
            }
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
     * Magento's cache frontend accepts only [A-Za-z0-9_] in ids; names are already that, this only guards.
     */
    private function cacheId(string $name): string
    {
        return self::CACHE_PREFIX . preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
    }
}
