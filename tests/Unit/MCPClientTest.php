<?php

use Client\MCPClient;

beforeAll(function () {
    $port = 19000 + mt_rand(1, 1000);
    $base = 'http://127.0.0.1:' . $port;
    $logFile = CORE_PATH . '/tmp/mcp_server_' . $port . '.log';
    $cmd = sprintf(
        'php -S 127.0.0.1:%d %s > %s 2>&1 & echo $!',
        $port,
        escapeshellarg(CORE_PATH . '/tests/Fixtures/mcp_server.php'),
        escapeshellarg($logFile)
    );
    $output = shell_exec($cmd);
    $pid = (int) trim((string) $output);

    // Wait for the server to accept connections
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($fp) {
            fclose($fp);
            $ready = true;
            break;
        }
        usleep(100000);
    }

    if (!$ready) {
        throw new \RuntimeException('Mock MCP server failed to start.');
    }

    $GLOBALS['mcp_base_url'] = $base;
    $GLOBALS['mcp_pid'] = $pid;
});

afterAll(function () {
    if (!empty($GLOBALS['mcp_pid'])) {
        @posix_kill((int) $GLOBALS['mcp_pid'], 15);
    }
});

test('MCPClient initializes and reads protocol version', function () {
    $client = new MCPClient($GLOBALS['mcp_base_url']);
    $info = $client->initialize();

    expect($client->isInitialized())->toBeTrue();
    expect($client->getProtocolVersion())->toBe('2025-03-26');
    expect($info['serverInfo']['name'])->toBe('test-mcp-server');
});

test('MCPClient lists tools', function () {
    $client = new MCPClient($GLOBALS['mcp_base_url']);
    $tools = $client->listTools();

    expect($tools)->toBeArray();
    $names = array_column($tools, 'name');
    expect($names)->toContain('echo');
    expect($names)->toContain('search');
});

test('MCPClient calls a tool and extracts text content', function () {
    $client = new MCPClient($GLOBALS['mcp_base_url']);
    $result = $client->callTool('echo', ['text' => 'hello world']);

    expect($result)->toBe(['echo: hello world']);
});

test('MCPClient auto-initializes before tool calls', function () {
    $client = new MCPClient($GLOBALS['mcp_base_url']);
    $result = $client->callTool('search', ['query' => 'minecraft crash']);

    expect($client->isInitialized())->toBeTrue();
    expect($result)->toBe(['result for minecraft crash']);
});

test('MCPClient decodes plain JSON responses', function () {
    $client = new MCPClient($GLOBALS['mcp_base_url']);
    $ref = new ReflectionClass($client);
    $method = $ref->getMethod('decodeResponse');

    $decoded = $method->invoke($client, '{"jsonrpc":"2.0","id":1,"result":{"ok":true}}');
    expect($decoded)->toMatchArray(['result' => ['ok' => true]]);
});

test('MCPClient decodes SSE framed responses', function () {
    $client = new MCPClient($GLOBALS['mcp_base_url']);
    $ref = new ReflectionClass($client);
    $method = $ref->getMethod('decodeResponse');

    $sse = "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"tools\":[]}}\n\n";
    $decoded = $method->invoke($client, $sse);
    expect($decoded)->toMatchArray(['result' => ['tools' => []]]);
});

test('MCPClient throws on unsupported method errors', function () {
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