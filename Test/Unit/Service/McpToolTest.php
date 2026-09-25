<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service;

use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mcp\Service\Client;
use MagoAssistant\Mcp\Service\InstructionGate;
use MagoAssistant\Mcp\Service\McpException;
use MagoAssistant\Mcp\Service\McpTool;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeMcpServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class McpToolTest extends TestCase
{
    private const QUERY_METRICS = [
        'name' => 'query-metrics-tool',
        'description' => 'Query Core Web Vitals field data. Supports breakdowns, filters and comparison periods.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => ['domain' => ['type' => 'string'], 'periods' => ['type' => 'object']],
            'required' => ['domain', 'periods'],
        ],
        'annotations' => ['readOnlyHint' => true],
    ];
    private const MARK_FIXED = [
        'name' => 'mark-issue-fixed-tool',
        'description' => 'Mark findings as fixed.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => ['domain' => ['type' => 'string'], 'issue_ids' => ['type' => 'array']],
            'required' => ['domain', 'issue_ids'],
        ],
    ];

    #[Test]
    public function exposesTheRemoteToolWithItsOwnSchemaAndFullDescription(): void
    {
        $tool = $this->tool(self::QUERY_METRICS);

        self::assertSame('mcp_test__query_metrics_tool', $tool->getName());
        self::assertSame('[Test Server] ' . self::QUERY_METRICS['description'], $tool->getDescription());
        self::assertSame(self::QUERY_METRICS['inputSchema'], $tool->getParameterSchema());
    }

    #[Test]
    public function givesAToolWithoutParametersAnObjectForProperties(): void
    {
        $schema = $this->tool(['name' => 'list-domains-tool', 'inputSchema' => ['type' => 'object', 'properties' => []]])
            ->getParameterSchema();

        self::assertSame('{"type":"object","properties":{}}', json_encode($schema));
    }

    #[Test]
    public function keepsNamesWithinProviderLimits(): void
    {
        $name = McpTool::nameFor('My Server', str_repeat('very-long-tool-name-', 5));

        self::assertLessThanOrEqual(64, strlen($name));
        self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $name);
        self::assertSame('mcp_my_server__get_top_issues', McpTool::nameFor('My Server', 'get.top-issues'));
    }

    #[Test]
    public function treatsAToolWithoutReadOnlyHintAsAWrite(): void
    {
        self::assertTrue($this->tool(self::QUERY_METRICS)->isReadOnly());
        self::assertFalse($this->tool(self::MARK_FIXED)->isReadOnly());
        self::assertFalse($this->tool(self::MARK_FIXED)->isReadOnlyAction([]));
    }

    #[Test]
    public function refusesAWriteWithoutItsRequiredParameters(): void
    {
        $tool = $this->tool(self::MARK_FIXED);

        self::assertSame(['error' => 'Missing required parameter(s): issue_ids'], $tool->findRefusal(['domain' => 'shop.nl']));
        self::assertNull($tool->findRefusal(['domain' => 'shop.nl', 'issue_ids' => [1]]));
    }

    #[Test]
    public function callsTheRemoteToolWithoutMagoInternalParameters(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())
            ->method('callTool')
            ->with(self::anything(), 'query-metrics-tool', ['domain' => 'shop.nl', 'periods' => ['current' => []]], 7)
            ->willReturn(['content' => [['type' => 'text', 'text' => '{"lcp":1800}']]]);

        $result = $this->tool(self::QUERY_METRICS, $client)->execute([
            'domain' => 'shop.nl',
            'periods' => ['current' => []],
            '_admin_user_id' => 7,
        ]);

        self::assertSame(['result' => ['lcp' => 1800]], $result);
    }

    #[Test]
    public function mapsStructuredContentPlainTextAndErrorsWithHints(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('callTool')->willReturnOnConsecutiveCalls(
            ['structuredContent' => ['rows' => [1]], 'content' => [['type' => 'text', 'text' => 'ignored']]],
            ['content' => [['type' => 'text', 'text' => 'line 1'], ['type' => 'text', 'text' => 'line 2']]],
            ['isError' => true, 'content' => [['type' => 'text', 'text' => 'This domain does not exist.']]],
            ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Rate limited']]],
            self::throwException(new McpException('MCP server "Test Server" is unreachable: timeout'))
        );
        $tool = $this->tool(self::QUERY_METRICS, $client);

        self::assertSame(['result' => ['rows' => [1]]], $tool->execute([]));
        self::assertSame(['result' => "line 1\nline 2"], $tool->execute([]));
        self::assertSame(['error' => 'This domain does not exist. List the domains first.'], $tool->execute([]));
        self::assertSame(['error' => 'Rate limited'], $tool->execute([]));
        self::assertSame(['error' => 'MCP server "Test Server" is unreachable: timeout'], $tool->execute([]));
    }

    #[Test]
    public function sendsTheServerInstructionsOnlyWithTheFirstToolUsedPerRequest(): void
    {
        $gate = new InstructionGate();
        $first = $this->tool(self::QUERY_METRICS, null, $gate, 'Call list-domains first.');
        $second = $this->tool(self::MARK_FIXED, null, $gate, 'Call list-domains first.');

        self::assertSame('Call list-domains first.', $first->getInstructions());
        self::assertSame('', $second->getInstructions());
        self::assertSame('', $first->getInstructions());
    }

    #[Test]
    public function takesTheClassificationForItsOwnRemoteTool(): void
    {
        $classification = [PiiClass::ANY => [PiiClass::PUBLIC]];
        $tool = new McpTool(
            new FakeMcpServer('test', null, [], $classification),
            $this->createStub(Client::class),
            self::QUERY_METRICS,
            new InstructionGate()
        );

        self::assertSame($classification, $tool->getFieldClassification(''));
    }

    /**
     * @param array<string, mixed> $remoteTool
     */
    private function tool(
        array $remoteTool,
        ?Client $client = null,
        ?InstructionGate $gate = null,
        string $instructions = ''
    ): McpTool {
        return new McpTool(
            new FakeMcpServer(),
            $client ?? $this->createStub(Client::class),
            $remoteTool,
            $gate ?? new InstructionGate(),
            $instructions
        );
    }
}
