<?php

use App\Client\MCPClient;

beforeAll(function () {
    $server = startMockServer(CORE_PATH . '/tests/Fixtures/mcp_server.php', 19000, 1000);
    if ($server === null) {
        $GLOBALS['mcp_base_url'] = null;
        $GLOBALS['mcp_pid'] = null;
        return;
    }

    $GLOBALS['mcp_base_url'] = $server['base'];
    $GLOBALS['mcp_pid'] = $server['pid'];
});

afterAll(function () {
    if (!empty($GLOBALS['mcp_pid'])) {
        @posix_kill((int) $GLOBALS['mcp_pid'], 15);
    }
});

test('MCPClient initializes and reads protocol version', function () {
    skipWithoutMockServer($GLOBALS['mcp_base_url']);
    $client = new MCPClient($GLOBALS['mcp_base_url']);
    $info = $client->initialize();

    expect($client->isInitialized())->toBeTrue();
    expect($client->getProtocolVersion())->toBe('2025-03-26');
    expect($info['serverInfo']['name'])->toBe('test-mcp-server');
});

test('MCPClient lists tools', function () {
    skipWithoutMockServer($GLOBALS['mcp_base_url']);
    $client = new MCPClient($GLOBALS['mcp_base_url']);
    $tools = $client->listTools();

    expect($tools)->toBeArray();
    $names = array_column($tools, 'name');
    expect($names)->toContain('echo');
    expect($names)->toContain('search');
});

test('MCPClient calls a tool and extracts text content', function () {
    skipWithoutMockServer($GLOBALS['mcp_base_url']);
    $client = new MCPClient($GLOBALS['mcp_base_url']);
    $result = $client->callTool('echo', ['text' => 'hello world']);

    expect($result)->toBe(['echo: hello world']);
});

test('MCPClient auto-initializes before tool calls', function () {
    skipWithoutMockServer($GLOBALS['mcp_base_url']);
    $client = new MCPClient($GLOBALS['mcp_base_url']);
    $result = $client->callTool('search', ['query' => 'minecraft crash']);

    expect($client->isInitialized())->toBeTrue();
    expect($result)->toBe(['result for minecraft crash']);
});

test('MCPClient decodes plain JSON responses', function () {
    skipWithoutMockServer($GLOBALS['mcp_base_url']);
    $client = new MCPClient($GLOBALS['mcp_base_url']);
    $ref = new ReflectionClass($client);
    $method = $ref->getMethod('decodeResponse');

    $decoded = $method->invoke($client, '{"jsonrpc":"2.0","id":1,"result":{"ok":true}}');
    expect($decoded)->toMatchArray(['result' => ['ok' => true]]);
});

test('MCPClient decodes SSE framed responses', function () {
    skipWithoutMockServer($GLOBALS['mcp_base_url']);
    $client = new MCPClient($GLOBALS['mcp_base_url']);
    $ref = new ReflectionClass($client);
    $method = $ref->getMethod('decodeResponse');

    $sse = "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"tools\":[]}}\n\n";
    $decoded = $method->invoke($client, $sse);
    expect($decoded)->toMatchArray(['result' => ['tools' => []]]);
});

test('MCPClient throws on unsupported method errors', function () {
    skipWithoutMockServer($GLOBALS['mcp_base_url']);
    $client = new MCPClient($GLOBALS['mcp_base_url']);
    $ref = new ReflectionClass($client);
    $method = $ref->getMethod('request');

    try {
        $method->invoke($client, 'unknown/method');
        $this->fail('Expected exception');
    } catch (\Exception $e) {
        expect($e->getMessage())->toContain('MCP request failed with HTTP 400');
    }
});