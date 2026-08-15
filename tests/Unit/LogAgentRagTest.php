<?php

use Agent\LogAgent;

beforeAll(function () {
    // Build a small index in tmp
    $dbPath = CORE_PATH . '/tmp/rag_loop_test_' . uniqid() . '.db';
    require_once CORE_PATH . '/rag/RagSearch.php';
    $rag = new RagSearch($dbPath);
    $rag->getPdo()->exec("INSERT INTO docs(title, body, source) VALUES
        ('OOM', 'java.lang.OutOfMemoryError 堆内存不足，调整 -Xmx', 'oom.md')");
    $GLOBALS['rag_loop_db'] = $dbPath;

    // Start the real RAG server and the mock LLM server via proc_open
    $ragPort = 19400 + mt_rand(1, 300);
    $llmPort = 19800 + mt_rand(1, 100);
    $GLOBALS['rag_loop_url'] = 'http://127.0.0.1:' . $ragPort;
    $GLOBALS['rag_loop_llm'] = 'http://127.0.0.1:' . $llmPort;

    $processes = [];
    $processes['rag'] = proc_open(
        'RAG_DB_PATH=' . escapeshellarg($dbPath) . ' php -S 127.0.0.1:' . $ragPort . ' ' . escapeshellarg(CORE_PATH . '/rag/server.php'),
        [0 => ['pipe', 'r'], 1 => ['file', CORE_PATH . '/tmp/rag_loop_rag.log', 'w'], 2 => ['file', CORE_PATH . '/tmp/rag_loop_rag.err.log', 'w']],
        $pipes
    );
    $processes['llm'] = proc_open(
        'php -S 127.0.0.1:' . $llmPort . ' ' . escapeshellarg(CORE_PATH . '/tests/Fixtures/llm_server.php'),
        [0 => ['pipe', 'r'], 1 => ['file', CORE_PATH . '/tmp/rag_loop_llm.log', 'w'], 2 => ['file', CORE_PATH . '/tmp/rag_loop_llm.err.log', 'w']],
        $pipes2
    );
    $GLOBALS['rag_loop_procs'] = $processes;

    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $fp = @fsockopen('127.0.0.1', $ragPort, $e1, $s1, 0.2);
        $fp2 = @fsockopen('127.0.0.1', $llmPort, $e2, $s2, 0.2);
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
        $ragErr = is_file(CORE_PATH . '/tmp/rag_loop_rag.err.log') ? trim((string) file_get_contents(CORE_PATH . '/tmp/rag_loop_rag.err.log')) : '';
        $llmErr = is_file(CORE_PATH . '/tmp/rag_loop_llm.err.log') ? trim((string) file_get_contents(CORE_PATH . '/tmp/rag_loop_llm.err.log')) : '';
        throw new RuntimeException('RAG/LLM servers failed to start' . ($ragErr !== '' ? ' [rag] ' . $ragErr : '') . ($llmErr !== '' ? ' [llm] ' . $llmErr : ''));
    }
});

afterAll(function () {
    foreach (($GLOBALS['rag_loop_procs'] ?? []) as $proc) {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
    }
    if (!empty($GLOBALS['rag_loop_db']) && file_exists($GLOBALS['rag_loop_db'])) {
        unlink($GLOBALS['rag_loop_db']);
    }
    @unlink(CORE_PATH . '/tmp/rag_loop_rag.log');
    @unlink(CORE_PATH . '/tmp/rag_loop_rag.err.log');
    @unlink(CORE_PATH . '/tmp/rag_loop_llm.log');
    @unlink(CORE_PATH . '/tmp/rag_loop_llm.err.log');
});

beforeEach(function () {
    $configRef = new ReflectionClass(\Config::class);
    $dataProp = $configRef->getProperty('data');
    $this->origData = $dataProp->getValue();

    $data = $this->origData;
    $data['ai'] = [
        'apiKeys' => ['mock-key-1'],
        'baseUrl' => $GLOBALS['rag_loop_llm'] . '/v1/chat/completions',
        'model' => 'mock-model',
        'timeout' => 10,
        'agent' => ['enabled' => true, 'maxToolRounds' => 3, 'maxFileLines' => 500, 'maxFileBytes' => 16 * 1024],
        'mcp' => [
            'webSearch' => [],
            'rag' => ['url' => $GLOBALS['rag_loop_url']],
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

test('LogAgent tool loop calls rag_search against the local RAG server', function () {
    ob_start();
    ob_start();
    LogAgent::analyze('server crashed with OutOfMemoryError');
    $output = ob_get_clean();

    // Tool registration (only rag_search since webSearch url is empty)
    expect($output)->toContain('rag_search');

    // Tool invocation event
    expect($output)->toContain('"type":"tool"');

    // Tool result event with knowledge content
    expect($output)->toContain('"type":"tool_result"');
    expect($output)->toContain('OOM');
    expect($output)->toContain('oom.md');

    // Final answer from the second LLM round
    expect($output)->toContain('最终分析结果');
    expect($output)->toContain('event: done');
});