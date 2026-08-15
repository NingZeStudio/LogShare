<?php

use Agent\LogAgent;

beforeEach(function () {
    $this->configRef = new ReflectionClass(\Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();
});

afterEach(function () {
    $this->dataProp->setValue(null, $this->origData);
});

function agentCall(string $method, array $args = [])
{
    $ref = new ReflectionClass(LogAgent::class);
    $m = $ref->getMethod($method);
    return $m->invoke(null, ...$args);
}

test('buildTools returns empty when no mcp endpoints configured', function () {
    $tools = agentCall('buildTools', [[], null]);
    expect($tools)->toBe([]);
});

test('buildTools registers web_search_exa when webSearch url is set', function () {
    $config = ['mcp' => ['webSearch' => ['url' => 'https://mcp.exa.ai/mcp']]];
    $tools = agentCall('buildTools', [$config, null]);

    expect($tools)->toHaveCount(1);
    expect($tools[0]['function']['name'])->toBe('web_search_exa');
    expect($tools[0]['function']['parameters']['required'])->toContain('query');
});

test('buildTools registers both tools when both urls are set', function () {
    $config = [
        'mcp' => [
            'webSearch' => ['url' => 'https://mcp.exa.ai/mcp'],
            'rag' => ['url' => 'http://127.0.0.1:9000'],
        ],
    ];
    $tools = agentCall('buildTools', [$config, null]);

    expect($tools)->toHaveCount(2);
    $names = array_column(array_column($tools, 'function'), 'name');
    expect($names)->toContain('web_search_exa');
    expect($names)->toContain('rag_search');
});

test('assistantMessageWithToolCalls formats tool calls', function () {
    $message = agentCall('assistantMessageWithToolCalls', [[
        ['id' => 'call_1', 'name' => 'web_search_exa', 'arguments' => '{"query":"x"}'],
    ]]);

    expect($message['role'])->toBe('assistant');
    expect($message['content'])->toBeNull();
    expect($message['tool_calls'][0]['id'])->toBe('call_1');
    expect($message['tool_calls'][0]['function']['name'])->toBe('web_search_exa');
});

test('truncateForModel keeps short text intact', function () {
    $result = agentCall('truncateForModel', [str_repeat('a', 500)]);
    expect($result)->toBe(str_repeat('a', 500));
});

test('truncateForModel truncates long text', function () {
    $result = agentCall('truncateForModel', [str_repeat('a', 20000)]);
    expect(strlen($result))->toBeLessThan(20000);
});

test('executeTool returns unknown tool message', function () {
    $result = agentCall('executeTool', ['nope_tool', [], [], null]);
    expect($result)->toContain('未知工具');
});

test('executeTool returns not configured message for unset endpoints', function () {
    $result = agentCall('executeTool', ['web_search_exa', ['query' => 'x'], [], null]);
    expect($result)->toContain('未配置');

    $result = agentCall('executeTool', ['rag_search', ['query' => 'x'], [], null]);
    expect($result)->toContain('未配置');
});

test('executeTool degrades gracefully when MCP call fails', function () {
    $config = ['mcp' => ['webSearch' => ['url' => 'http://127.0.0.1:1']]];
    $result = agentCall('executeTool', ['web_search_exa', ['query' => 'x'], $config, null]);
    expect($result)->toContain('工具调用失败');
});