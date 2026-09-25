<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Tool\Mcp;

use MagoAssistant\Mago\Api\Config\RepositoryInterface as MagoConfig;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Catalog\CatalogEntry;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Mcp\McpException;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use MagoAssistant\Mcp\Service\Transport\TransportResolver;

/**
 * Runs one MCP tool for a skill: argument decoding, coercion and validation, the call itself, and the
 * shaping of the result. Shared by every McpSkill so the per-tool objects stay small.
 */
class Executor
{
    public function __construct(
        private readonly ToolCatalog $catalog,
        private readonly TransportResolver $transports,
        private readonly Config $config,
        private readonly DebugLogger $debugLogger,
        private readonly ErrorLogger $errorLogger,
        private readonly MagoConfig $magoConfig
    ) {
    }

    /**
     * The decoded, coerced arguments; [] when nothing usable was given.
     *
     * @return array<string,mixed>
     */
    public function arguments(CatalogEntry $entry, mixed $arguments): array
    {
        return $this->coerce($entry, $this->decodeArguments($arguments));
    }

    /**
     * Why the arguments cannot be sent, or null when they can. Wording as before:
     * "missing required argument "x"", ""x" must be integer".
     */
    public function problemWith(CatalogEntry $entry, mixed $arguments): ?string
    {
        return $this->validate($entry, $this->coerce($entry, $this->decodeArguments($arguments)));
    }

    /**
     * @param array<string,mixed> $arguments already passed problemWith()
     * @return array{server:string,action:string,result:mixed}|array{error:string}
     */
    public function run(CatalogEntry $entry, array $arguments, ?int $adminUserId = null): array
    {
        $server = $this->catalog->serverConfig($entry->server);
        if ($server === null) {
            return ['error' => sprintf('MCP server "%s" is not enabled.', $entry->server)];
        }
        $skill = McpSkill::nameFor($entry->server, $entry->tool);
        // Keys only: Mago rehydrates privacy tokens before execute(), so the values here are real.
        $this->debug('MCP call', ['skill' => $skill, 'argument_keys' => array_keys($arguments)]);
        try {
            $result = $this->transports->for($server)->callTool($server, $entry->tool, $arguments, $adminUserId);
        } catch (McpException $e) {
            $this->errorLogger->addLog('MCP call failed', ['skill' => $skill, 'error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }
        $texts = $this->textBlocks($result);
        $isError = ($result['isError'] ?? false) === true;
        // Metadata only: Mago keeps customer data out of its logs even in debug mode, and an MCP result
        // is arbitrary text the administrator already sees in the chat.
        $this->debug('MCP result', [
            'skill' => $skill,
            'is_error' => $isError,
            'blocks' => is_array($result['content'] ?? null) ? count($result['content']) : 0,
            'text_length' => mb_strlen(implode("\n\n", $texts)),
        ]);
        if ($isError) {
            $error = $texts === [] ? 'The MCP tool reported an error.' : implode("\n", $texts);

            return ['error' => $this->withHint($server, $error)];
        }

        return [
            'server' => $entry->server,
            'action' => $entry->tool,
            'result' => $this->shape($server, $result, $texts),
        ];
    }

    /**
     * A public server's result reaches the model as data: structuredContent, else a single text block
     * that is JSON of an object or list. A non-public server's result is always one string, so Mago's
     * heuristic scrub and vault concealment run over the whole of it; structured content with no text
     * is JSON-encoded for the same reason. Text is truncated to the configured maximum; data is not,
     * Mago's own token cap applies to it.
     *
     * @param array<string,mixed> $result
     * @param string[] $texts
     */
    private function shape(ServerConfig $server, array $result, array $texts): mixed
    {
        $structured = is_array($result['structuredContent'] ?? null) ? $result['structuredContent'] : null;
        if ($server->outputPublic) {
            if ($structured !== null) {
                return $structured;
            }
            if (count($texts) === 1) {
                $decoded = json_decode($texts[0], true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            return $this->text($result, $texts);
        }
        if ($texts === [] && $structured !== null) {
            return $this->truncate((string)json_encode($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $this->text($result, $texts);
    }

    /**
     * The first configured hint whose fragment appears in the error, appended after a space.
     */
    private function withHint(ServerConfig $server, string $error): string
    {
        foreach ($server->errorHints as $fragment => $hint) {
            if ($fragment !== '' && str_contains($error, (string)$fragment)) {
                return $error . ' ' . $hint;
            }
        }

        return $error;
    }

    /**
     * Mago hands a skill its input as an array; anything else is treated as no arguments.
     *
     * @return array<string,mixed>
     */
    private function decodeArguments(mixed $arguments): array
    {
        return is_array($arguments) ? $arguments : [];
    }

    /**
     * Models sometimes send "3" for an integer or "true" for a boolean. When the schema names the
     * scalar type, convert such strings so the server receives what it asked for; anything else is
     * left for validate() to reject.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function coerce(CatalogEntry $entry, array $arguments): array
    {
        $properties = $entry->inputSchema['properties'] ?? [];
        foreach (is_array($properties) ? $properties : [] as $key => $property) {
            if (!is_array($property)
                || !array_key_exists($key, $arguments)
                || !is_string($arguments[$key])
                || !is_string($property['type'] ?? null)
            ) {
                continue;
            }
            $value = trim($arguments[$key]);
            $arguments[$key] = match ($property['type']) {
                'integer' => (preg_match('/^-?\d+$/', $value) ? (int)$value : $arguments[$key]),
                'number' => (is_numeric($value) ? $value + 0 : $arguments[$key]),
                'boolean' => match (strtolower($value)) {
                    'true' => true, 'false' => false, default => $arguments[$key]
                },
                default => $arguments[$key],
            };
        }

        return $arguments;
    }

    /**
     * Required keys and top-level scalar types only. The server validates the rest and its error comes
     * back to the model verbatim. A property schema that is not an array (true, or {} kept as stdClass)
     * and a "required" that is not a list are skipped rather than trusted.
     *
     * @param array<string,mixed> $arguments
     */
    private function validate(CatalogEntry $entry, array $arguments): ?string
    {
        $required = $entry->inputSchema['required'] ?? [];
        foreach (is_array($required) ? $required : [] as $key) {
            if (is_scalar($key) && !array_key_exists((string)$key, $arguments)) {
                return sprintf('missing required argument "%s"', $key);
            }
        }
        $properties = $entry->inputSchema['properties'] ?? [];
        foreach (is_array($properties) ? $properties : [] as $key => $property) {
            if (!is_array($property) || !array_key_exists($key, $arguments) || !is_string($property['type'] ?? null)) {
                continue;
            }
            if (!$this->matchesType($arguments[$key], $property['type'])) {
                return sprintf('"%s" must be %s', $key, $property['type']);
            }
        }

        return null;
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value),
            'null' => $value === null,
            default => true,
        };
    }

    /**
     * @param array<string,mixed> $result
     * @return string[] the text of every text block, in order
     */
    private function textBlocks(array $result): array
    {
        $texts = [];
        foreach (is_array($result['content'] ?? null) ? $result['content'] : [] as $block) {
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $texts[] = $block['text'];
            }
        }

        return $texts;
    }

    /**
     * @param array<string,mixed> $result
     * @param string[] $texts
     */
    private function text(array $result, array $texts): string
    {
        if ($texts === []) {
            $other = [];
            foreach (is_array($result['content'] ?? null) ? $result['content'] : [] as $block) {
                $other[] = (string)($block['type'] ?? 'unknown');
            }
            $count = count($other);
            $kind = $count === 1 ? $other[0] : 'non-text';

            return sprintf('(no text content: %d %s block%s)', $count, $kind, $count === 1 ? '' : 's');
        }

        return $this->truncate(implode("\n\n", $texts));
    }

    private function truncate(string $text): string
    {
        $max = $this->config->getMaxResultChars();
        if (mb_strlen($text) > $max) {
            return mb_substr($text, 0, $max) . sprintf(' [truncated: %d more characters]', mb_strlen($text) - $max);
        }

        return $text;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function debug(string $type, array $data): void
    {
        if ($this->magoConfig->isDebugEnabled()) {
            $this->debugLogger->addLog($type, $data);
        }
    }
}
