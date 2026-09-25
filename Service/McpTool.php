<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service;

use MagoAssistant\Mcp\Api\ServerInterface;
use MagoAssistant\Mago\Api\Tool\ActionScopedToolInterface;
use MagoAssistant\Mago\Api\Tool\ValidatingToolInterface;

/**
 * All tools of one MCP server as a single assistant tool: each remote tool is an action.
 */
class McpTool implements ActionScopedToolInterface, ValidatingToolInterface
{
    private const SHORT_DESCRIPTION_LENGTH = 200;

    /** @var array<string, array<string, mixed>> Remote tool definitions keyed by name */
    private readonly array $remoteTools;

    /**
     * @param ServerInterface $server
     * @param Client $client
     * @param array<int, array<string, mixed>> $remoteTools As returned by tools/list
     * @param string $serverInstructions The instructions the server sent on initialize
     */
    public function __construct(
        private readonly ServerInterface $server,
        private readonly Client $client,
        array $remoteTools,
        private readonly string $serverInstructions = ''
    ) {
        $byName = [];
        foreach ($remoteTools as $tool) {
            $byName[(string)$tool['name']] = $tool;
        }
        $this->remoteTools = $byName;
    }

    public function getName(): string
    {
        return 'mcp_' . (preg_replace('/[^a-z0-9_]+/', '_', strtolower($this->server->getCode())) ?? '');
    }

    public function getDescription(): string
    {
        return $this->buildDescription(array_keys($this->remoteTools));
    }

    public function getDescriptionForActions(array $actionNames): string
    {
        return $this->buildDescription($this->selectActions($actionNames));
    }

    public function getParameterSchema(): array
    {
        return $this->buildParameterSchema(array_keys($this->remoteTools));
    }

    public function getParameterSchemaForActions(array $actionNames): array
    {
        return $this->buildParameterSchema($this->selectActions($actionNames));
    }

    public function execute(array $params): array
    {
        $action = (string)($params['action'] ?? '');
        if (!isset($this->remoteTools[$action])) {
            return ['error' => 'Unknown action: ' . $action];
        }
        $adminUserId = isset($params['_admin_user_id']) ? (int)$params['_admin_user_id'] : null;
        unset($params['action'], $params['_admin_user_id']);

        try {
            $result = $this->client->callTool($this->server, $action, $params, $adminUserId);
        } catch (McpException $e) {
            return ['error' => $e->getMessage()];
        }

        return $this->mapResult($result);
    }

    public function isReadOnly(): bool
    {
        foreach (array_keys($this->remoteTools) as $name) {
            if (!$this->isReadOnlyAction(['action' => $name])) {
                return false;
            }
        }

        return true;
    }

    public function isReadOnlyAction(array $input): bool
    {
        // MCP's readOnlyHint defaults to false: a tool that does not declare it is treated as a write.
        $tool = $this->remoteTools[(string)($input['action'] ?? '')] ?? null;

        return $tool !== null && ($tool['annotations']['readOnlyHint'] ?? false) === true;
    }

    public function findRefusal(array $input): ?array
    {
        $action = (string)($input['action'] ?? '');
        if (!isset($this->remoteTools[$action])) {
            return ['error' => 'Unknown action: ' . $action];
        }

        $required = $this->remoteTools[$action]['inputSchema']['required'] ?? [];
        $missing = array_values(array_filter(
            is_array($required) ? $required : [],
            static fn ($name): bool => !isset($input[$name]) || $input[$name] === ''
        ));

        return $missing !== []
            ? ['error' => sprintf('Missing required parameter(s) for %s: %s', $action, implode(', ', $missing))]
            : null;
    }

    public function getInstructions(): string
    {
        $parts = [];
        if (trim($this->serverInstructions) !== '') {
            $parts[] = trim($this->serverInstructions);
        }
        // The tool description only carries the first sentence per action; the full text goes here.
        foreach ($this->remoteTools as $name => $tool) {
            $description = trim((string)($tool['description'] ?? ''));
            if ($description !== '' && $description !== $this->shortDescription($description)) {
                $parts[] = '## ' . $name . "\n" . $description;
            }
        }

        return implode("\n\n", $parts);
    }

    public function getFieldClassification(string $action = ''): array
    {
        return isset($this->remoteTools[$action]) ? $this->server->getFieldClassification($action) : [];
    }

    public function getMagentoAcl(array $input = []): string
    {
        return '';
    }

    /**
     * @param string[] $actionNames
     */
    private function buildDescription(array $actionNames): string
    {
        $parts = [];
        foreach ($actionNames as $name) {
            $description = (string)($this->remoteTools[$name]['description'] ?? '');
            $parts[] = '"' . $name . '" (' . $this->shortDescription($description) . ')';
        }

        return sprintf('Tools from the external MCP server "%s".', $this->server->getLabel())
            . ' Actions: ' . implode(', ', $parts) . '.';
    }

    /**
     * @param string[] $actionNames
     * @return array<string, mixed>
     */
    private function buildParameterSchema(array $actionNames): array
    {
        $properties = [];
        foreach ($actionNames as $name) {
            $remoteProperties = $this->remoteTools[$name]['inputSchema']['properties'] ?? [];
            foreach (is_array($remoteProperties) ? $remoteProperties : [] as $paramName => $paramSchema) {
                if ($paramName !== 'action' && !isset($properties[$paramName])) {
                    $properties[$paramName] = $paramSchema;
                }
            }
        }

        return [
            'type' => 'object',
            'properties' => array_merge(
                ['action' => ['type' => 'string', 'enum' => $actionNames, 'description' => 'The action to perform']],
                $properties
            ),
            'required' => ['action'],
        ];
    }

    /**
     * @param string[] $actionNames
     * @return string[]
     */
    private function selectActions(array $actionNames): array
    {
        return array_values(array_filter(
            array_keys($this->remoteTools),
            static fn (string $name): bool => in_array($name, $actionNames, true)
        ));
    }

    private function shortDescription(string $description): string
    {
        $description = trim(preg_replace('/\s+/', ' ', $description) ?? '');
        if (preg_match('/^.+?[.!?](?=\s|$)/', $description, $match)) {
            $description = $match[0];
        }

        return mb_strlen($description) > self::SHORT_DESCRIPTION_LENGTH
            ? rtrim(mb_substr($description, 0, self::SHORT_DESCRIPTION_LENGTH - 1)) . '…'
            : $description;
    }

    /**
     * @param array<string, mixed> $result MCP CallToolResult
     * @return array<string, mixed>
     */
    private function mapResult(array $result): array
    {
        $texts = [];
        foreach ($result['content'] ?? [] as $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'text' && is_string($item['text'] ?? null)) {
                $texts[] = $item['text'];
            }
        }

        if (($result['isError'] ?? false) === true) {
            return ['error' => $texts !== [] ? implode("\n", $texts) : 'The MCP tool reported an error.'];
        }
        if (is_array($result['structuredContent'] ?? null)) {
            return ['result' => $result['structuredContent']];
        }
        if (count($texts) === 1) {
            $decoded = json_decode($texts[0], true);
            if (is_array($decoded)) {
                return ['result' => $decoded];
            }
        }

        return ['result' => implode("\n", $texts)];
    }
}
