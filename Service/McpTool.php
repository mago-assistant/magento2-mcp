<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service;

use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Api\Tool\ValidatingToolInterface;
use MagoAssistant\Mcp\Api\ServerInterface;

/**
 * One remote MCP tool as an assistant tool, with the remote tool's own schema. One tool per remote
 * tool (instead of one tool with an action per remote tool) keeps each schema exact: with a merged
 * schema models dropped the action and mixed up parameters that differ between tools.
 */
class McpTool implements ToolInterface, ValidatingToolInterface
{
    // OpenAI rejects tool names longer than 64 characters.
    private const MAX_NAME_LENGTH = 64;

    /**
     * @param ServerInterface $server
     * @param Client $client
     * @param array<string, mixed> $remoteTool One entry of tools/list
     * @param InstructionGate $instructionGate
     * @param string $serverInstructions The instructions the server sent on initialize
     */
    public function __construct(
        private readonly ServerInterface $server,
        private readonly Client $client,
        private readonly array $remoteTool,
        private readonly InstructionGate $instructionGate,
        private readonly string $serverInstructions = ''
    ) {
    }

    public function getServer(): ServerInterface
    {
        return $this->server;
    }

    public function getRemoteName(): string
    {
        return (string)$this->remoteTool['name'];
    }

    public function getName(): string
    {
        return self::nameFor($this->server->getCode(), $this->getRemoteName());
    }

    /**
     * mcp_<server>__<remote tool>, restricted to the characters and length every AI provider accepts
     */
    public static function nameFor(string $serverCode, string $remoteName): string
    {
        $slug = static fn (string $value): string => trim(
            (string)preg_replace('/[^a-z0-9_]+/', '_', strtolower($value)),
            '_'
        );
        $name = 'mcp_' . $slug($serverCode) . '__' . $slug($remoteName);
        if (strlen($name) > self::MAX_NAME_LENGTH) {
            $name = substr($name, 0, self::MAX_NAME_LENGTH - 9) . '_' . substr(hash('sha256', $name), 0, 8);
        }

        return $name;
    }

    public function getDescription(): string
    {
        $description = trim((string)($this->remoteTool['description'] ?? ''));

        return sprintf(
            '[%s] %s',
            $this->server->getLabel(),
            $description !== '' ? $description : $this->getRemoteName()
        );
    }

    public function getParameterSchema(): array
    {
        $schema = is_array($this->remoteTool['inputSchema'] ?? null) ? $this->remoteTool['inputSchema'] : [];
        $schema['type'] = 'object';
        // Providers reject an object schema without properties; an empty list would encode as [].
        if (!is_array($schema['properties'] ?? null) || $schema['properties'] === []) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    public function execute(array $params): array
    {
        $adminUserId = isset($params['_admin_user_id']) ? (int)$params['_admin_user_id'] : null;
        unset($params['_admin_user_id']);

        try {
            $result = $this->client->callTool($this->server, $this->getRemoteName(), $params, $adminUserId);
        } catch (McpException $e) {
            return ['error' => $e->getMessage()];
        }

        return $this->mapResult($result);
    }

    public function isReadOnly(): bool
    {
        // MCP's readOnlyHint defaults to false: a tool that does not declare it is treated as a write.
        return ($this->remoteTool['annotations']['readOnlyHint'] ?? false) === true;
    }

    public function isReadOnlyAction(array $input): bool
    {
        return $this->isReadOnly();
    }

    public function findRefusal(array $input): ?array
    {
        $required = $this->remoteTool['inputSchema']['required'] ?? [];
        $missing = array_values(array_filter(
            is_array($required) ? $required : [],
            static fn ($name): bool => !isset($input[$name]) || $input[$name] === ''
        ));

        return $missing !== []
            ? ['error' => sprintf('Missing required parameter(s): %s', implode(', ', $missing))]
            : null;
    }

    public function getInstructions(): string
    {
        // The server's instructions cover all its tools: sent with the first of them used per request.
        $instructions = trim($this->serverInstructions);

        return $instructions !== '' && $this->instructionGate->claim($this->server->getCode()) ? $instructions : '';
    }

    public function getFieldClassification(string $action = ''): array
    {
        return $this->server->getFieldClassification($this->getRemoteName());
    }

    public function getMagentoAcl(array $input = []): string
    {
        return '';
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
            $error = $texts !== [] ? implode("\n", $texts) : 'The MCP tool reported an error.';
            $hint = $this->server->getErrorHint($this->getRemoteName(), $error);

            return ['error' => $hint !== '' ? $error . ' ' . $hint : $error];
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
