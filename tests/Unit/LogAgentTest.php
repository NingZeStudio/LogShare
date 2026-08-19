<?php

use App\Agent\LogAgent;

beforeEach(function () {
    $this->configRef = new ReflectionClass(\App\Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $data = $this->origData;
    $data['storage']['storages']['f'] = [
        'name' => 'Filesystem',
        'class' => '\\App\\Storage\\FilesystemStorage',
        'enabled' => true,
    ];
    $data['storage']['storageId'] = 'f';
    $data['cache']['enabled'] = false;

    $this->tmpDir = CORE_PATH . '/tmp/logshare_test_' . uniqid();
    mkdir($this->tmpDir, 0777, true);
    $data['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $this->dataProp->setValue(null, $data);
});

afterEach(function () {
    $this->dataProp->setValue(null, $this->origData);
    if (is_dir($this->tmpDir)) {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tmpDir);
    }
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

    expect($tools)->toHaveCount(3);
    $names = array_column(array_column($tools, 'function'), 'name');
    expect($names)->toContain('web_search_exa');
    expect($names)->toContain('rag_search');
    expect($names)->toContain('list_topics');
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

test('file tools return not-bound message without a log id', function () {
    $result = agentCall('executeTool', ['list_log_files', [], [], null]);
    expect($result)->toContain('未绑定日志文件');

    $result = agentCall('executeTool', ['read_log_file', ['filename' => 'main'], [], null]);
    expect($result)->toContain('未绑定日志文件');
});

test('list_log_files lists session files with metadata', function () {
    $log = new \App\Log();
    $id = $log->put(
        "main line 1\nmain line 2\n",
        null,
        [],
        'test',
        [
            ['name' => 'crash-reports/crash-01.txt', 'data' => "java.lang.Error\nat a.b.c\nat c.d.e\n"],
            ['name' => 'debug.txt', 'data' => "GL: OpenGL 3.2\n"],
        ]
    );
    $rawId = $id->get();

    $result = agentCall('executeTool', ['list_log_files', [], [], $rawId]);
    expect($result)->toContain('main（主文件');
    expect($result)->toContain('crash-reports/crash-01.txt');
    expect($result)->toContain('debug.txt');
});

test('read_log_file reads main file with default range', function () {
    $log = new \App\Log();
    $id = $log->put("line1\nline2\nline3\n", null, [], null, null);
    $rawId = $id->get();

    $result = agentCall('executeTool', ['read_log_file', ['filename' => 'main'], [], $rawId]);
    expect($result)->toContain('共 3 行');
    expect($result)->toContain('line1');
    expect($result)->toContain('line3');
});

test('read_log_file supports line ranges', function () {
    $log = new \App\Log();
    $id = $log->put(implode("\n", array_map(fn($i) => "line{$i}", range(1, 10))), null, [], null, null);
    $rawId = $id->get();

    $result = agentCall('executeTool', ['read_log_file', ['filename' => 'main', 'start_line' => 3, 'end_line' => 5], [], $rawId]);
    expect($result)->toContain('第 3-5 行');
    expect($result)->toContain('line3');
    expect($result)->toContain('line4');
    expect($result)->toContain('line5');
    expect($result)->not->toContain('line1');
    expect($result)->not->toContain('line6');
});

test('read_log_file returns not found for missing files', function () {
    $log = new \App\Log();
    $id = $log->put("main\n", null, [], null, null);
    $rawId = $id->get();

    $result = agentCall('executeTool', ['read_log_file', ['filename' => 'missing.log'], [], $rawId]);
    expect($result)->toContain('文件不存在');
});

test('read_log_file does not leak other logs', function () {
    $logA = new \App\Log();
    $idA = $logA->put("secret from A\n", null, [], null, null)->get();

    $logB = new \App\Log();
    $idB = $logB->put("public from B\n", null, [], null, null)->get();

    // Reading "main" under id B must not return A's content
    $result = agentCall('executeTool', ['read_log_file', ['filename' => 'main'], [], $idB]);
    expect($result)->toContain('public from B');
    expect($result)->not->toContain('secret from A');
});