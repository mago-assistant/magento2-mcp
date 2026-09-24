<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use MagoAssistant\Mcp\Api\McpClientInterface;
use MagoAssistant\Mcp\Service\Mcp\McpException;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;

final class FakeMcpClient implements McpClientInterface
{
    /** @var array<string,array<int,array<string,mixed>>> server name => tools */
    public array $tools = [];
    /** @var array<string,string> server name => initialize instructions */
    public array $instructions = [];
    /** @var array<string,string> "server" or "server:tool" => failure message */
    public array $failures = [];
    /** @var array<int,array{0:string,1:string,2:array}> */
    public array $calls = [];
    /** @var array<string,mixed> */
    public array $nextResult = ['content' => [['type' => 'text', 'text' => 'ok']], 'isError' => false];
    public int $listCalls = 0;

    public function listTools(ServerConfig $server, int $timeoutSeconds): array
    {
        $this->listCalls++;
        if (isset($this->failures[$server->name])) {
            throw new McpException($this->failures[$server->name]);
        }

        return [
            'tools' => $this->tools[$server->name] ?? [],
            'serverInfo' => ['name' => $server->name, 'version' => '0'],
            'instructions' => $this->instructions[$server->name] ?? '',
        ];
    }

    public function callTool(ServerConfig $server, string $tool, array $arguments, int $timeoutSeconds): array
    {
        $this->calls[] = [$server->name, $tool, $arguments];
        if (isset($this->failures[$server->name . ':' . $tool])) {
            throw new McpException($this->failures[$server->name . ':' . $tool]);
        }

        return $this->nextResult;
    }
}
