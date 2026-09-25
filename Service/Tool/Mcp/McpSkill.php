<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Tool\Mcp;

use MagoAssistant\Mago\Api\Tool\IrreversibleToolInterface;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Api\Tool\ValidatingToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mcp\Service\Catalog\CatalogEntry;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;

/**
 * One MCP tool as one Mago skill, so it sits in Skills & Permissions beside Mago's own skills with
 * its own per-user permission. The name is "<server>__<tool>" because AI providers accept only
 * letters, digits, "_" and "-" in tool names. Its type (read or write) comes from the catalog entry;
 * who may use it is Mago's role ACL and per-user permission, as for any other skill. The description
 * carries "[personal data]" and "[runs code, SQL or commands]" when the tool's name says so.
 */
class McpSkill implements ToolInterface, IrreversibleToolInterface, ValidatingToolInterface
{
    public const SEPARATOR = '__';
    private const DESCRIPTION_LENGTH = 300;
    private const SERVER_INSTRUCTIONS_LENGTH = 2000;

    public function __construct(
        private readonly CatalogEntry $entry,
        private readonly Executor $executor,
        private readonly string $serverInstructions = '',
        private readonly bool $executionSurface = false
    ) {
    }

    public static function nameFor(string $server, string $tool): string
    {
        $safe = static fn (string $s): string => trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $s) ?? '', '-');

        return $safe($server) . self::SEPARATOR . $safe($tool);
    }

    public function entry(): CatalogEntry
    {
        return $this->entry;
    }

    public function getName(): string
    {
        return self::nameFor($this->entry->server, $this->entry->tool);
    }

    public function getDescription(): string
    {
        $first = preg_split('/(?<=[.!?])\s+/', trim($this->entry->description), 2)[0] ?? '';
        if (mb_strlen($first) > self::DESCRIPTION_LENGTH) {
            $first = rtrim(mb_substr($first, 0, self::DESCRIPTION_LENGTH - 1)) . '…';
        }
        $text = sprintf('%s: %s', $this->entry->server, $this->entry->tool) . ($first === '' ? '' : ' — ' . $first);
        if ($this->entry->personalData) {
            $text .= ' [personal data]';
        }
        if ($this->executionSurface) {
            $text .= ' [runs code, SQL or commands]';
        }

        return $text;
    }

    /**
     * The tool's own input schema. Mago's ToolRegistry and ChatService index $schema['properties'] and
     * $schema['properties']['action'] as arrays, which throws on the stdClass the catalog keeps for an
     * empty {}: so properties itself is an array (a schema with no properties goes out as
     * {"type":"object"}), a non-empty property entry is an array, and an empty "action" entry is []. Any
     * other empty property entry and everything deeper keep their stdClass, so they re-encode as {}.
     *
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array
    {
        $schema = $this->entry->inputSchema;
        $schema['type'] = 'object';
        $properties = $schema['properties'] ?? null;
        if ($properties instanceof \stdClass) {
            $properties = (array)$properties;
        }
        if (is_array($properties) && $properties !== []) {
            $schema['properties'] = array_map(
                static fn (mixed $property): mixed => $property instanceof \stdClass
                    && ((array)$property !== []) ? (array)$property : $property,
                $properties
            );
            // Mago reads $schema['properties']['action']['enum'], which fails on an object.
            if (($schema['properties']['action'] ?? null) instanceof \stdClass) {
                $schema['properties']['action'] = [];
            }
        } else {
            unset($schema['properties']);
        }

        return $schema;
    }

    public function findRefusal(array $input): ?array
    {
        $problem = $this->executor->problemWith($this->entry, $this->toolArguments($input));

        return $problem === null ? null : ['error' => sprintf(
            'Invalid arguments for %s: %s. Expected schema: %s',
            $this->getName(),
            $problem,
            json_encode($this->entry->inputSchema, JSON_UNESCAPED_SLASHES)
        )];
    }

    public function execute(array $params): array
    {
        return $this->findRefusal($params)
            ?? $this->executor->run(
                $this->entry,
                $this->executor->arguments($this->entry, $this->toolArguments($params))
            );
    }

    public function isReadOnly(): bool
    {
        return $this->entry->mode === ModeClassifier::READ;
    }

    public function isReadOnlyAction(array $input): bool
    {
        return $this->isReadOnly();
    }

    public function getInstructions(): string
    {
        $blocks = [];
        if (trim($this->serverInstructions) !== '') {
            $blocks[] = sprintf(
                "## Server %s\n%s",
                $this->entry->server,
                mb_substr(trim($this->serverInstructions), 0, self::SERVER_INSTRUCTIONS_LENGTH)
            );
        }
        $blocks[] = sprintf(
            "## %s\n%s\nPass arguments exactly as the schema names them. Input schema: %s",
            $this->getName(),
            trim($this->entry->description),
            json_encode($this->entry->inputSchema, JSON_UNESCAPED_SLASHES)
        );

        return implode("\n\n", $blocks);
    }

    public function getFieldClassification(string $action = ''): array
    {
        return [
            'server' => [PiiClass::PUBLIC],
            'action' => [PiiClass::PUBLIC],
            'content' => [PiiClass::PUBLIC],
            'is_error' => [PiiClass::PUBLIC],
        ];
    }

    public function getMagentoAcl(array $input = []): string
    {
        return $this->isReadOnly() ? 'MagoAssistant_Mcp::use' : 'MagoAssistant_Mcp::use_write';
    }

    public function isIrreversibleAction(array $input): bool
    {
        return $this->entry->irreversible;
    }

    public function getImpacts(array $input, int $adminUserId): array
    {
        return [
            sprintf('Runs %s tool "%s" with the given arguments', $this->entry->server, $this->entry->tool),
            'The assistant cannot undo this',
        ];
    }

    /**
     * The input without Mago's reserved keys: ChatService adds "_admin_user_id" (and may add other
     * "_" keys) before execute(), and those are Mago's, not the MCP server's.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function toolArguments(array $input): array
    {
        return array_filter(
            $input,
            static fn ($key): bool => !str_starts_with((string)$key, '_'),
            ARRAY_FILTER_USE_KEY
        );
    }
}
