<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Tool;

use MagoAssistant\Mago\Api\Config\RepositoryInterface as MagoConfig;
use MagoAssistant\Mago\Api\Tool\ActionScopedToolInterface;
use MagoAssistant\Mago\Api\Tool\IrreversibleToolInterface;
use MagoAssistant\Mago\Api\Tool\ValidatingToolInterface;
use MagoAssistant\Mago\Logger\DebugLogger;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mcp\Api\McpClientInterface;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Catalog\CatalogEntry;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Mcp\McpException;

/**
 * The one Mago skill that fronts every enabled MCP server.
 *
 * "action" is "<server>:<tool>" so Mago's per-action read/write narrowing, confirmation cards and ACL
 * checks work unchanged; the per-action input schemas travel in the just-in-time instructions and in
 * validation errors, which keeps the always-sent description short. findRefusal() answers a bad write
 * before Mago shows the confirmation card.
 */
class McpTool implements ActionScopedToolInterface, IrreversibleToolInterface, ValidatingToolInterface
{
    public const NAME = 'mcp';
    private const NO_ACTION = 'none';
    private const SUMMARY_LENGTH = 80;
    private const SERVER_INSTRUCTIONS_LENGTH = 2000;

    public function __construct(
        private readonly ToolCatalog $catalog,
        private readonly McpClientInterface $client,
        private readonly Config $config,
        private readonly DebugLogger $debugLogger,
        private readonly ErrorLogger $errorLogger,
        private readonly MagoConfig $magoConfig
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return $this->getDescriptionForActions($this->actionNames());
    }

    public function getDescriptionForActions(array $actionNames): string
    {
        $entries = $this->entriesFor($actionNames);
        if ($entries === []) {
            return 'No MCP servers are enabled. Do not call this tool.';
        }
        $counts = [];
        foreach ($entries as $entry) {
            $counts[$entry->server] = ($counts[$entry->server] ?? 0) + 1;
        }
        $parts = [];
        foreach ($counts as $server => $count) {
            $parts[] = sprintf('%s (%d action%s)', $server, $count, $count === 1 ? '' : 's');
        }

        return 'Call tools exposed by installed MCP servers. Servers: ' . implode(', ', $parts)
            . '. Set action to server:tool and pass its arguments.';
    }

    public function getParameterSchema(): array
    {
        return $this->getParameterSchemaForActions($this->actionNames());
    }

    public function getParameterSchemaForActions(array $actionNames): array
    {
        $entries = $this->entriesFor($actionNames);
        $lines = ['server:tool to call.'];
        foreach ($entries as $entry) {
            $lines[] = $entry->action() . ' — ' . $this->summary($entry->description);
        }
        $enum = $entries === []
            ? [self::NO_ACTION]
            : array_map(static fn (CatalogEntry $e): string => $e->action(), $entries);

        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => $enum,
                    'description' => implode("\n", $lines),
                ],
                'arguments' => [
                    'type' => 'object',
                    'description' => 'Arguments for the chosen action, as its schema requires.',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function findRefusal(array $input): ?array
    {
        $action = (string)($input['action'] ?? '');
        $entry = $this->catalog->find($action);
        if ($entry === null) {
            return ['error' => $this->unknownActionMessage($action)];
        }
        $arguments = $this->decodeArguments($input['arguments'] ?? []);
        $problem = $arguments === null
            ? 'arguments must be a JSON object'
            : $this->validate($entry, $this->coerce($entry, $arguments));
        if ($problem !== null) {
            return ['error' => $this->invalidArgumentsMessage($entry, $problem)];
        }

        return null;
    }

    public function execute(array $params): array
    {
        $refusal = $this->findRefusal($params);
        if ($refusal !== null) {
            return $refusal;
        }
        $action = (string)$params['action'];
        $entry = $this->catalog->find($action);
        $arguments = $entry === null ? [] : $this->coerce($entry, $this->decodeArguments($params['arguments'] ?? []) ?? []);
        $server = $entry === null ? null : $this->catalog->serverConfig($entry->server);
        if ($entry === null || $server === null) {
            return ['error' => sprintf('MCP server for "%s" is not enabled.', $action)];
        }
        // Keys only: Mago rehydrates privacy tokens before execute(), so the values here are real.
        $this->debug('MCP call', ['action' => $action, 'argument_keys' => array_keys($arguments)]);
        try {
            $result = $this->client->callTool($server, $entry->tool, $arguments, $this->config->getProcessTimeout());
        } catch (McpException $e) {
            $this->errorLogger->addLog('MCP call failed', ['action' => $action, 'error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }
        $content = $this->textOf($result);
        // Metadata only: Mago keeps customer data out of its logs even in debug mode, and an MCP result
        // is arbitrary text the administrator already sees in the chat.
        $this->debug('MCP result', [
            'action' => $action,
            'is_error' => (bool)($result['isError'] ?? false),
            'blocks' => is_array($result['content'] ?? null) ? count($result['content']) : 0,
            'text_length' => mb_strlen($content),
        ]);

        return [
            'server' => $entry->server,
            'action' => $entry->tool,
            'content' => $content,
            'is_error' => (bool)($result['isError'] ?? false),
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function isReadOnlyAction(array $input): bool
    {
        return $this->entryOf($input)?->mode === ModeClassifier::READ;
    }

    public function getInstructions(): string
    {
        $entries = $this->catalog->entries();
        if ($entries === []) {
            return '';
        }
        $blocks = ['Pass arguments exactly as each schema names them. Results are the server\'s text output.'];
        foreach ($this->catalog->serverInstructions() as $server => $text) {
            $blocks[] = sprintf("## Server %s\n%s", $server, mb_substr(trim($text), 0, self::SERVER_INSTRUCTIONS_LENGTH));
        }
        foreach ($entries as $entry) {
            $blocks[] = sprintf(
                "## %s\n%s\nInput schema: %s",
                $entry->action(),
                trim($entry->description),
                json_encode($entry->inputSchema, JSON_UNESCAPED_SLASHES)
            );
        }

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
        return $this->isReadOnlyAction($input) ? 'MagoAssistant_Mcp::use' : 'MagoAssistant_Mcp::use_write';
    }

    public function isIrreversibleAction(array $input): bool
    {
        return $this->entryOf($input)?->irreversible ?? false;
    }

    public function getImpacts(array $input, int $adminUserId): array
    {
        $entry = $this->entryOf($input);
        if ($entry === null) {
            return [];
        }

        return [
            sprintf('Runs %s tool "%s" with the given arguments', $entry->server, $entry->tool),
            'The assistant cannot undo this',
        ];
    }

    /**
     * @return string[]
     */
    private function actionNames(): array
    {
        return array_map(static fn (CatalogEntry $e): string => $e->action(), $this->catalog->entries());
    }

    /**
     * @param string[] $actionNames
     * @return CatalogEntry[]
     */
    private function entriesFor(array $actionNames): array
    {
        $wanted = array_flip($actionNames);

        return array_values(array_filter(
            $this->catalog->entries(),
            static fn (CatalogEntry $e): bool => isset($wanted[$e->action()])
        ));
    }

    /**
     * @param array<string,mixed> $input
     */
    private function entryOf(array $input): ?CatalogEntry
    {
        return $this->catalog->find((string)($input['action'] ?? ''));
    }

    private function unknownActionMessage(string $action): string
    {
        return sprintf(
            'Unknown or disabled MCP action "%s". Valid actions: %s',
            $action,
            implode(', ', $this->actionNames()) ?: 'none'
        );
    }

    private function invalidArgumentsMessage(CatalogEntry $entry, string $problem): string
    {
        return sprintf(
            'Invalid arguments for %s: %s. Expected schema: %s',
            $entry->action(),
            $problem,
            json_encode($entry->inputSchema, JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @return array<string,mixed>|null null when a string was given that is not a JSON object
     */
    private function decodeArguments(mixed $arguments): ?array
    {
        if (is_array($arguments)) {
            return $arguments;
        }
        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);

            return is_array($decoded) ? $decoded : null;
        }

        return [];
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
            if (!array_key_exists($key, $arguments) || !is_string($arguments[$key]) || !is_string($property['type'] ?? null)) {
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
     * back to the model verbatim.
     *
     * @param array<string,mixed> $arguments
     */
    private function validate(CatalogEntry $entry, array $arguments): ?string
    {
        foreach ($entry->inputSchema['required'] ?? [] as $key) {
            if (!array_key_exists((string)$key, $arguments)) {
                return sprintf('missing required argument "%s"', $key);
            }
        }
        $properties = $entry->inputSchema['properties'] ?? [];
        foreach (is_array($properties) ? $properties : [] as $key => $property) {
            if (!array_key_exists($key, $arguments) || !is_string($property['type'] ?? null)) {
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
     */
    private function textOf(array $result): string
    {
        $texts = [];
        $other = [];
        foreach (is_array($result['content'] ?? null) ? $result['content'] : [] as $block) {
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $texts[] = $block['text'];
            } else {
                $other[] = (string)($block['type'] ?? 'unknown');
            }
        }
        if ($texts === []) {
            $count = count($other);
            $kind = $count === 1 ? $other[0] : 'non-text';

            return sprintf('(no text content: %d %s block%s)', $count, $kind, $count === 1 ? '' : 's');
        }
        $text = implode("\n\n", $texts);
        $max = $this->config->getMaxResultChars();
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max) . sprintf(' [truncated: %d more characters]', mb_strlen($text) - $max);
        }

        return $text;
    }

    private function summary(string $description): string
    {
        $first = preg_split('/(?<=[.!?])\s+/', trim($description), 2)[0] ?? '';
        if (mb_strlen($first) > self::SUMMARY_LENGTH) {
            $first = rtrim(mb_substr($first, 0, self::SUMMARY_LENGTH - 1)) . '…';
        }

        return $first;
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
