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
    if ($method === 'executeTool') {
        $args[count($args) - 1] = new \App\Agent\ToolSession();
    }
    return $m->invokeArgs(null, $args);
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

test('truncateForModel truncates long text with a visible marker', function () {
    $result = agentCall('truncateForModel', [str_repeat('a', 20000)]);
    expect(strlen($result))->toBeLessThan(20000);
    // 截断必须可见：模型需要知道结果不完整
    expect($result)->toContain('已截断至');
});

test('executeTool returns unknown tool message', function () {
    $result = agentCall('executeTool', ['nope_tool', [], [], null, []]);
    expect($result)->toContain('未知工具');
});

test('executeTool returns not configured message for unset endpoints', function () {
    $result = agentCall('executeTool', ['web_search_exa', ['query' => 'x'], [], null, []]);
    expect($result)->toContain('未配置');

    $result = agentCall('executeTool', ['rag_search', ['query' => 'x'], [], null, []]);
    expect($result)->toContain('未配置');
});

test('executeTool degrades gracefully when MCP call fails', function () {
    $config = ['mcp' => ['webSearch' => ['url' => 'http://127.0.0.1:1']]];
    $result = agentCall('executeTool', ['web_search_exa', ['query' => 'x'], $config, null, []]);
    expect($result)->toContain('工具调用失败');
});

test('file tools return not-bound message without a log id', function () {
    $result = agentCall('executeTool', ['list_log_files', [], [], null, []]);
    expect($result)->toContain('未绑定日志文件');

    $result = agentCall('executeTool', ['read_log_file', ['filename' => 'main'], [], null, []]);
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

    $result = agentCall('executeTool', ['list_log_files', [], [], $rawId, []]);
    expect($result)->toContain('main（主文件');
    expect($result)->toContain('crash-reports/crash-01.txt');
    expect($result)->toContain('debug.txt');
});

test('read_log_file returns full file content by default', function () {
    $log = new \App\Log();
    $id = $log->put("line1\nline2\nline3\n", null, [], null, null);
    $rawId = $id->get();

    $result = agentCall('executeTool', ['read_log_file', ['filename' => 'main'], [], $rawId, []]);
    expect($result)->toContain('共 3 行');
    expect($result)->toContain('line1');
    expect($result)->toContain('line3');
});

test('read_log_file rejects duplicate reads of the same file', function () {
    $log = new \App\Log();
    $id = $log->put("main\n", null, [], null, null);
    $rawId = $id->get();

    // Shared session across both calls (as within one analyze() run)
    $session = new \App\Agent\ToolSession();
    $ref = new ReflectionClass(LogAgent::class);
    $m = $ref->getMethod('executeTool');

    $first = $m->invoke(null, 'read_log_file', ['filename' => 'main'], [], $rawId, $session);
    expect($first)->toContain('内容：');

    // Second read with identical arguments: duplicate notice
    $second = $m->invoke(null, 'read_log_file', ['filename' => 'main'], [], $rawId, $session);
    expect($second)->toContain('已读取');
    expect($second)->toContain('内容已在上文');
    expect($second)->toContain('不要重复调用');
});

test('read_log_file dedup treats omitted filename and main as the same key', function () {
    $log = new \App\Log();
    $id = $log->put("body\n", null, [], null, null);
    $rawId = $id->get();

    $session = new \App\Agent\ToolSession();
    $ref = new ReflectionClass(LogAgent::class);
    $m = $ref->getMethod('executeTool');

    $first = $m->invoke(null, 'read_log_file', ['filename' => 'main'], [], $rawId, $session);
    expect($first)->toContain('内容：');

    // Omitted filename resolves to main: must hit the duplicate guard
    $second = $m->invoke(null, 'read_log_file', [], [], $rawId, $session);
    expect($second)->toContain('已读取');
    expect($second)->not->toContain('内容：');
});

test('read_log_file returns not found for missing files', function () {
    $log = new \App\Log();
    $id = $log->put("main\n", null, [], null, null);
    $rawId = $id->get();

    $result = agentCall('executeTool', ['read_log_file', ['filename' => 'missing.log'], [], $rawId, []]);
    expect($result)->toContain('文件不存在');
});

test('read_log_file does not leak other logs', function () {
    $logA = new \App\Log();
    $idA = $logA->put("secret from A\n", null, [], null, null)->get();

    $logB = new \App\Log();
    $idB = $logB->put("public from B\n", null, [], null, null)->get();

    // Reading "main" under id B must not return A's content
    $result = agentCall('executeTool', ['read_log_file', ['filename' => 'main'], [], $idB, []]);
    expect($result)->toContain('public from B');
    expect($result)->not->toContain('secret from A');
});
/* ─── Agentic RAG Plus：工具描述 / 检索策略 / 预算兜底 / crash-reports 优先 ─── */

function agentSystemPrompt(array $config = [], ?string $logId = null, string $topicsText = ''): string
{
    $ref = new ReflectionClass(LogAgent::class);
    $m = $ref->getMethod('buildMessages');
    $messages = $m->invoke(null, '测试日志内容', $logId, $config, $topicsText);
    return $messages[0]['content'];
}

test('rag_search tool schema includes topic', function () {
    $config = ['mcp' => ['rag' => ['url' => 'http://127.0.0.1:9000']]];
    $tools = agentCall('buildTools', [$config, null]);
    $rag = array_values(array_filter($tools, fn($t) => $t['function']['name'] === 'rag_search'));

    expect($rag)->toHaveCount(1);
    expect($rag[0]['function']['parameters']['properties'])->toHaveKey('topic');
    expect($rag[0]['function']['description'])->toContain('知识库');
});

test('system prompt uses evidence-driven retrieval', function () {
    $system = agentSystemPrompt();

    expect($system)->not->toContain('必须先调用一次 rag_search');
    expect($system)->toContain('检索策略');
    expect($system)->toContain('不超过 2 次');
    expect($system)->toContain('最多 5 次');
});

test('topic routing rule present', function () {
    $system = agentSystemPrompt();
    expect($system)->toContain('patterns');
    expect($system)->toContain('不要硬套目录');
    expect($system)->toContain('topic 参数');
});

test('few-shot example present', function () {
    $system = agentSystemPrompt();
    expect($system)->toContain('MixinApplyError');
    expect($system)->toContain('示例（正确的检索路径）');
});

test('system prompt has crash-report priority', function () {
    $system = agentSystemPrompt();
    expect($system)->toContain('crash-reports');
    expect($system)->toContain('信息密度高于 latest.log');
});

test('web search hard-capped at 5', function () {
    $session = new \App\Agent\ToolSession();
    $session->webSearchCalls = 5;

    $ref = new ReflectionClass(LogAgent::class);
    $m = $ref->getMethod('executeTool');
    // 空配置下若不拦截会返回「未配置」；拦截分支必须返回上限文案且不发起调用
    $result = $m->invoke(null, 'web_search_exa', ['query' => 'x'], [], null, $session);

    expect($result)->toContain('网络搜索次数已达本次分析上限');
    expect($session->webSearchCalls)->toBe(5);
});

test('retrieval budget reminder injected', function () {
    $session = new \App\Agent\ToolSession();
    $session->ragSearchCalls = 5;
    $session->webSearchCalls = 0;

    $ref = new ReflectionClass(LogAgent::class);
    $m = $ref->getMethod('executeTool');
    // rag_search 仍执行（此处未配置端点，返回未配置文案），但合计达 6 需追加收敛提示
    $result = $m->invoke(null, 'rag_search', ['query' => 'x'], [], null, $session);

    expect($session->ragSearchCalls)->toBe(6);
    expect($result)->toContain('检索预算提示');
});

test('list_log_files ranks crash reports first', function () {
    $log = new \App\Log();
    $id = $log->put(
        "main\n",
        null,
        [],
        null,
        [
            ['name' => 'debug.txt', 'data' => "d\n"],
            ['name' => 'crash-reports/crash-2024-01-01_12.00.00.txt', 'data' => "c\n"],
            ['name' => 'latest.log', 'data' => "l\n"],
            ['name' => 'crash-reports.txt', 'data' => "x\n"],
        ]
    )->get();

    $result = agentCall('executeTool', ['list_log_files', [], [], $id, []]);
    $lines = array_values(array_filter(explode("\n", $result), fn($l) => str_starts_with($l, '- ')));

    // crash-report 类（含目录形态与裸文件名）置顶并标注 [优先]，其余保持原顺序
    expect($lines[1])->toContain('[优先] crash-reports/crash-2024-01-01_12.00.00.txt');
    expect($lines[2])->toContain('[优先] crash-reports.txt');
    expect($lines[3])->toContain('debug.txt');
    expect($lines[4])->toContain('latest.log');
    expect($lines[3])->not->toContain('[优先]');
});
