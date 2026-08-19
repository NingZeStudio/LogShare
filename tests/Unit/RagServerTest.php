<?php

use App\Client\MCPClient;

beforeAll(function () {
    // Build a small index in tmp
    $dbPath = CORE_PATH . '/tmp/rag_server_test_' . uniqid() . '.db';
    require_once CORE_PATH . '/rag/RagSearch.php';
    $rag = new RagSearch($dbPath);
    $pdo = $rag->getPdo();
    $pdo->exec("INSERT INTO docs(title, body, source) VALUES
        ('OOM', 'java.lang.OutOfMemoryError 堆内存不足，调整 -Xmx', 'oom.md'),
        ('启动', '加载 mod 过多导致启动慢', 'startup.md')");
    $GLOBALS['rag_db'] = $dbPath;

    $port = 19300 + mt_rand(1, 400);
    $logFile = CORE_PATH . '/tmp/rag_server_test_' . uniqid() . '.log';
    $cmd = sprintf(
        'RAG_DB_PATH=%s php -S 127.0.0.1:%d %s > %s 2>&1 & echo $!',
        escapeshellarg($dbPath),
        $port,
        escapeshellarg(CORE_PATH . '/rag/server.php'),
        escapeshellarg($logFile)
    );
    $pid = (int) trim((string) shell_exec($cmd));

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
        $stderr = is_file($logFile) ? trim((string) file_get_contents($logFile)) : '';
        throw new RuntimeException('RAG server failed to start' . ($stderr !== '' ? ': ' . $stderr : ''));
    }
    @unlink($logFile);

    $GLOBALS['rag_url'] = 'http://127.0.0.1:' . $port;
    $GLOBALS['rag_pid'] = $pid;
});

afterAll(function () {
    if (!empty($GLOBALS['rag_pid'])) {
        @posix_kill((int) $GLOBALS['rag_pid'], 15);
    }
    if (!empty($GLOBALS['rag_db']) && file_exists($GLOBALS['rag_db'])) {
        unlink($GLOBALS['rag_db']);
    }
});

test('rag server initializes and exposes rag_search tool', function () {
    $client = new MCPClient($GLOBALS['rag_url']);
    $info = $client->initialize();

    expect($info['serverInfo']['name'])->toBe('logshare-rag');

    $tools = $client->listTools();
    $names = array_column($tools, 'name');
    expect($names)->toContain('rag_search');
    expect($names)->toContain('list_topics');
});

test('list_topics returns knowledge base topic overview', function () {
    $client = new MCPClient($GLOBALS['rag_url']);
    $result = $client->callTool('list_topics');

    expect($result)->toHaveCount(1);
    expect($result[0])->toContain('主题目录');
    // OOM fixture doc lives at the root level, shown without extension
    expect($result[0])->toContain('oom');
});

test('rag_search returns matching knowledge entries', function () {
    $client = new MCPClient($GLOBALS['rag_url']);
    $result = $client->callTool('rag_search', ['query' => 'OutOfMemoryError', 'k' => 2]);

    expect($result)->toHaveCount(1);
    expect($result[0])->toContain('OOM');
    expect($result[0])->toContain('oom.md');
});

test('rag_search supports CJK queries', function () {
    $client = new MCPClient($GLOBALS['rag_url']);
    $result = $client->callTool('rag_search', ['query' => '启动慢']);

    expect($result[0])->toContain('启动');
});

test('rag_search returns no-match message for unknown terms', function () {
    $client = new MCPClient($GLOBALS['rag_url']);
    $result = $client->callTool('rag_search', ['query' => 'zzzunknownterm']);

    expect($result[0])->toContain('未在知识库中找到');
});

test('rag_search rejects empty query', function () {
    $client = new MCPClient($GLOBALS['rag_url']);
    $ref = new ReflectionClass($client);
    $method = $ref->getMethod('request');

    try {
        $method->invoke($client, 'tools/call', [
            'name' => 'rag_search',
            'arguments' => (object) ['query' => ''],
        ]);
        expect(true)->toBeFalse();
    } catch (\Exception $e) {
        expect($e->getMessage())->toContain('rag_search requires a non-empty query');
    }
});