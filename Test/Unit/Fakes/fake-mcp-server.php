#!/usr/bin/env php
<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

// A tiny MCP server over stdio for unit tests. Exits when stdin closes.
// FAKE_MCP_CRASH=1 exits 3 before answering; FAKE_MCP_VERSION overrides the negotiated version;
// FAKE_MCP_INSTRUCTIONS adds client instructions to the initialize result.

if (getenv('FAKE_MCP_CRASH') === '1') {
    fwrite(STDERR, "boom: fake crash\n");
    exit(3);
}

$tools = [
    ['name' => 'echo-args', 'description' => 'Echo the arguments back. Second sentence is long.',
        'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']]],
    ['name' => 'item-delete', 'description' => 'Delete an item.',
        'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],
    ['name' => 'slow-get', 'description' => 'Sleeps five seconds.',
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]],
    ['name' => 'image-get', 'description' => 'Returns only an image block.',
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]],
    ['name' => 'weather', 'title' => 'Weather lookup', 'description' => 'Annotated as read-only.',
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        'annotations' => ['readOnlyHint' => true]],
    ['name' => 'stock-get', 'description' => 'Named like a read but annotated as a write.',
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true]],
];

$send = static function (array $message): void {
    fwrite(STDOUT, json_encode($message, JSON_UNESCAPED_SLASHES) . "\n");
    fflush(STDOUT);
};

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $request = json_decode($line, true);
    if (!is_array($request) || !isset($request['id'])) {
        continue; // notification, or the client's reply to our ping
    }
    $id = $request['id'];
    switch ($request['method'] ?? '') {
        case 'initialize':
            $result = ['protocolVersion' => getenv('FAKE_MCP_VERSION') ?: '2025-06-18',
                'capabilities' => ['tools' => new stdClass()], 'serverInfo' => ['name' => 'fake-mcp', 'version' => '0.1']];
            if (getenv('FAKE_MCP_INSTRUCTIONS')) {
                $result['instructions'] = getenv('FAKE_MCP_INSTRUCTIONS');
            }
            $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
            break;
        case 'tools/list':
            if (($request['params']['cursor'] ?? null) === null) {
                $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => [$tools[0], $tools[1]], 'nextCursor' => 'page2']]);
            } else {
                $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => [$tools[2], $tools[3], $tools[4], $tools[5]]]]);
            }
            break;
        case 'tools/call':
            $name = $request['params']['name'] ?? '';
            $args = $request['params']['arguments'] ?? [];
            if ($name === 'echo-args') {
                $send(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => ['level' => 'info', 'data' => 'working']]);
                $send(['jsonrpc' => '2.0', 'id' => 'srv-ping-1', 'method' => 'ping']);
                $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => json_encode($args)]], 'isError' => false]]);
            } elseif ($name === 'item-delete') {
                $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => 'Item not found']], 'isError' => true]]);
            } elseif ($name === 'slow-get') {
                sleep(5);
                $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => 'done']], 'isError' => false]]);
            } elseif ($name === 'image-get') {
                $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'image', 'data' => 'AAAA', 'mimeType' => 'image/png']], 'isError' => false]]);
            } else {
                $send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Unknown tool: ' . $name]]);
            }
            break;
        default:
            $send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }
}
