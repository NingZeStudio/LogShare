<?php

use Agent\LogAgent;

beforeAll(function () {
    // Start mock MCP server
    $mcpPort = 19200 + mt_rand(1, 500);
    $mcpCmd = sprintf(
        'php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!',
        $mcpPort,
        escapeshellarg(CORE_PATH . '/tests/Fixtures/mcp_server.php')
    );
    $mcpPid = (int) trim((string) shell_exec($mcpCmd));

    $llmPort = 19700 + mt_rand(1, 500);
    $llmCmd = sprintf(
        'php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!',
        $llmPort,
        escapeshellarg(CORE_PATH . '/tests/Fixtures/llm_server.php')
    );
    $llmPid = (int) trim((string) shell_exec($llmCmd));

    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $fp = @fsockopen('127.0.0.1', $mcpPort, $errno, $errstr, 0.2);
        $fp2 = @fsockopen('127.0.0.1', $llmPort, $errno2, $errstr2, 0.2);
        if ($fp && $fp2) {
            fclose($fp);
            fclose($fp2);
            $ready = true;
            break;
        }
        if ($fp) {
            fclose($fp);
        }
        if ($fp2) {
            fclose($fp2);
        }
        usleep(100000);
    }

    if (!$ready) {
        throw new \RuntimeException('Mock servers failed to start.');
    }

    $GLOBALS['mcp_pid'] = $mcpPid;
    $GLOBALS['llm_pid'] = $llmPid;
    $GLOBALS['mcp_url'] = 'http://127.0.0.1:' . $mcpPort;
    $GLOBALS['llm_url'] = 'http://127.0.0.1:' . $llmPort;
});

afterAll(function () {
    if (!empty($GLOBALS['mcp_pid'])) {
        @posix_kill((int) $GLOBALS['mcp_pid'], 15);
    }
    if (!empty($GLOBALS['llm_pid'])) {
        @posix_kill((int) $GLOBALS['llm_pid'], 15);
    }
});

beforeEach(function () {
    $configRef = new ReflectionClass(\Config::class);
    $dataProp = $configRef->getProperty('data');
    $this->origData = $dataProp->getValue();

    $data = $this->origData;
    $data['ai'] = [
        'apiKeys' => ['mock-key-1'],
        'baseUrl' => $GLOBALS['llm_url'] . '/v1/chat/completions',
        'model' => 'mock-model',
        'timeout' => 10,
        'mcp' => [
            'webSearch' => ['url' => $GLOBALS['mcp_url']],
            'rag' => [],
        ],
    ];
    $data['cache']['enabled'] = false;
    $dataProp->setValue(null, $data);
});

afterEach(function () {
    $configRef = new ReflectionClass(\Config::class);
    $dataProp = $configRef->getProperty('data');
    $dataProp->setValue(null, $this->origData);
});

test('LogAgent runs a full tool loop and streams SSE events', function () {
    ob_start();
    ob_start();
    LogAgent::analyze('some minecraft crash log', ['logId' => 'aB3x9K']);
    $output = ob_get_clean();

    // Thinking trace from the model
    expect($output)->toContain('event: status');
    expect($output)->toContain('"type":"thinking"');

    // Tool invocation event
    expect($output)->toContain('"type":"tool"');
    expect($output)->toContain('web_search_exa');

    // Tool result event
    expect($output)->toContain('"type":"tool_result"');

    // Final answer content (second round)
    expect($output)->toContain('最终分析结果');

    // Completion event
    expect($output)->toContain('event: done');
});

test('LogAgent streams a plain answer when no tools are configured', function () {
    $configRef = new ReflectionClass(\Config::class);
    $dataProp = $configRef->getProperty('data');
    $data = $dataProp->getValue();
    $data['ai']['mcp'] = [];
    $dataProp->setValue(null, $data);

    ob_start();
    ob_start();
    LogAgent::analyze('hello log');
    $output = ob_get_clean();

    expect($output)->toContain('Hello');
    expect($output)->toContain(' World');
    expect($output)->toContain('event: done');
    // No tool events when tools are absent
    expect($output)->not->toContain('"type":"tool"');
});