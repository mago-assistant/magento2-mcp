<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service;

use MagoAssistant\Mcp\Service\Client;
use MagoAssistant\Mcp\Service\McpException;
use MagoAssistant\Mcp\Service\McpTool;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeMcpServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class McpToolTest extends TestCase
{
    private const REMOTE_TOOLS = [
        [
            'name' => 'query-metrics-tool',
            'description' => 'Query Core Web Vitals field data. Supports breakdowns, filters and comparison periods.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['domain' => ['type' => 'string'], 'metrics' => ['type' => 'array']],
                'required' => ['domain', 'metrics'],
            ],
            'annotations' => ['readOnlyHint' => true],
        ],
        [
            'name' => 'mark-issue-fixed-tool',
            'description' => 'Mark findings as fixed.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['domain' => ['type' => 'integer'], 'issue_ids' => ['type' => 'array']],
                'required' => ['domain', 'issue_ids'],
            ],
        ],
    ];

    #[Test]
    public function exposesEachRemoteToolAsAnAction(): void
    {
        $tool = $this->tool();

        $schema = $tool->getParameterSchema();

        self::assertSame('mcp_test', $tool->getName());
        self::assertSame(['query-metrics-tool', 'mark-issue-fixed-tool'], $schema['properties']['action']['enum']);
        self::assertSame(['type' => 'string'], $schema['properties']['domain'], 'first declaration of a shared parameter wins');
        self::assertArrayHasKey('issue_ids', $schema['properties']);
        self::assertSame(['action'], $schema['required']);
    }

    #[Test]
    public function describesActionsWithTheirFirstSentenceAndKeepsTheRestForInstructions(): void
    {
        $tool = $this->tool('Use sample counts.');

        self::assertStringContainsString('"query-metrics-tool" (Query Core Web Vitals field data.)', $tool->getDescription());
        self::assertStringNotContainsString('comparison periods', $tool->getDescription());
        self::assertStringStartsWith('Use sample counts.', $tool->getInstructions());
        self::assertStringContainsString("## query-metrics-tool\nQuery Core Web Vitals field data. Supports", $tool->getInstructions());
        self::assertStringNotContainsString('## mark-issue-fixed-tool', $tool->getInstructions());
    }

    #[Test]
    public function treatsToolsWithoutReadOnlyHintAsWrites(): void
    {
        $tool = $this->tool();

        self::assertTrue($tool->isReadOnlyAction(['action' => 'query-metrics-tool']));
        self::assertFalse($tool->isReadOnlyAction(['action' => 'mark-issue-fixed-tool']));
        self::assertFalse($tool->isReadOnlyAction(['action' => 'unknown']));
        self::assertFalse($tool->isReadOnly());
        self::assertSame(['query-metrics-tool'], $tool->getParameterSchemaForActions(['query-metrics-tool'])['properties']['action']['enum']);
        self::assertArrayNotHasKey('issue_ids', $tool->getParameterSchemaForActions(['query-metrics-tool'])['properties']);
    }

    #[Test]
    public function refusesAWriteWithoutItsRequiredParameters(): void
    {
        $tool = $this->tool();

        self::assertSame(
            ['error' => 'Missing required parameter(s) for mark-issue-fixed-tool: issue_ids'],
            $tool->findRefusal(['action' => 'mark-issue-fixed-tool', 'domain' => 3])
        );
        self::assertNull($tool->findRefusal(['action' => 'mark-issue-fixed-tool', 'domain' => 3, 'issue_ids' => [1]]));
    }

    #[Test]
    public function callsTheRemoteToolWithoutMagoInternalParameters(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())
            ->method('callTool')
            ->with(self::anything(), 'query-metrics-tool', ['domain' => 'example.com', 'metrics' => ['lcp']], 7)
            ->willReturn(['content' => [['type' => 'text', 'text' => '{"lcp":1800}']]]);

        $result = $this->tool('', $client)->execute([
            'action' => 'query-metrics-tool',
            'domain' => 'example.com',
            'metrics' => ['lcp'],
            '_admin_user_id' => 7,
        ]);

        self::assertSame(['result' => ['lcp' => 1800]], $result);
    }

    #[Test]
    public function mapsStructuredContentPlainTextAndErrors(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('callTool')->willReturnOnConsecutiveCalls(
            ['structuredContent' => ['rows' => [1]], 'content' => [['type' => 'text', 'text' => 'ignored']]],
            ['content' => [['type' => 'text', 'text' => 'line 1'], ['type' => 'text', 'text' => 'line 2']]],
            ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Rate limited, retry in 30s']]],
            self::throwException(new McpException('MCP server "Test Server" is unreachable: timeout'))
        );
        $tool = $this->tool('', $client);
        $call = ['action' => 'query-metrics-tool'];

        self::assertSame(['result' => ['rows' => [1]]], $tool->execute($call));
        self::assertSame(['result' => "line 1\nline 2"], $tool->execute($call));
        self::assertSame(['error' => 'Rate limited, retry in 30s'], $tool->execute($call));
        self::assertSame(['error' => 'MCP server "Test Server" is unreachable: timeout'], $tool->execute($call));
    }

    #[Test]
    public function takesTheClassificationFromTheServerForKnownActionsOnly(): void
    {
        $classification = [PiiClass::ANY => [PiiClass::PUBLIC]];
        $tool = new McpTool(
            new FakeMcpServer('test', null, [], $classification),
            $this->createMock(Client::class),
            self::REMOTE_TOOLS
        );

        self::assertSame($classification, $tool->getFieldClassification('query-metrics-tool'));
        self::assertSame([], $tool->getFieldClassification('unknown'));
    }

    private function tool(string $instructions = '', ?Client $client = null): McpTool
    {
        return new McpTool(new FakeMcpServer(), $client ?? $this->createMock(Client::class), self::REMOTE_TOOLS, $instructions);
    }
}
