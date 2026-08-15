<?php

use Client\AIClient;

beforeAll(function () {
    $port = 19100 + mt_rand(1, 800);
    $base = 'http://127.0.0.1:' . $port;
    $cmd = sprintf(
        'php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!',
        $port,
        escapeshellarg(CORE_PATH . '/tests/Fixtures/llm_server.php')
    );
    $output = shell_exec($cmd);
    $pid = (int) trim((string) $output);

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
        throw new \RuntimeException('Mock LLM server failed to start.');
    }

    $GLOBALS['llm_base_url'] = $base;
    $GLOBALS['llm_pid'] = $pid;

    // Inject mock LLM endpoint into Config
    $configRef = new ReflectionClass(\Config::class);
    $dataProp = $configRef->getProperty('data');
    $data = $dataProp->getValue();
    $data['ai'] = [
        'apiKeys' => ['mock-key-1', 'mock-key-2'],
        'baseUrl' => $base . '/v1/chat/completions',
        'model' => 'mock-model',
        'timeout' => 10,
    ];
    $data['cache']['enabled'] = false;
    $dataProp->setValue(null, $data);
});

afterAll(function () {
    if (!empty($GLOBALS['llm_pid'])) {
        @posix_kill((int) $GLOBALS['llm_pid'], 15);
    }
});

test('streamChat forwards content and reasoning deltas without tools', function () {
    $content = '';
    $reasoning = '';
    $toolCalls = 'not-called';

    AIClient::streamChat(
        [['role' => 'user', 'content' => 'hi']],
        [],
        function ($delta) use (&$content) {
            $content .= $delta;
        },
        function ($delta) use (&$reasoning) {
            $reasoning .= $delta;
        },
        function ($calls) use (&$toolCalls) {
            $toolCalls = $calls;
        },
        function ($full, $hasTools) use (&$content) {
            $content = $full;
        }
    );

    expect($reasoning)->toBe('思考中');
    expect($content)->toBe('Hello World');
    expect($toolCalls)->toBe([]);
});

test('streamChat merges streamed tool_calls fragments', function () {
    $received = [];

    AIClient::streamChat(
        [['role' => 'user', 'content' => 'search something']],
        [['type' => 'function', 'function' => ['name' => 'web_search_exa', 'description' => 'search']]],
        function () {
        },
        function () {
        },
        function ($calls) use (&$received) {
            $received = $calls;
        },
        function () {
        }
    );

    expect($received)->toHaveCount(1);
    $call = $received[0];
    expect($call['id'])->toBe('call_1');
    expect($call['name'])->toBe('web_search_exa');
    expect(json_decode($call['arguments'], true))->toMatchArray(['query' => 'minecraft crash']);
});

test('streamChat continues with tool result messages', function () {
    $content = '';
    $done = false;

    AIClient::streamChat(
        [
            ['role' => 'user', 'content' => 'search'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'web_search_exa', 'arguments' => '{}']]]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'result here'],
        ],
        [],
        function ($delta) use (&$content) {
            $content .= $delta;
        },
        function () {
        },
        function () {
        },
        function ($full, $hasTools) use (&$content, &$done) {
            $content = $full;
            $done = true;
        }
    );

    expect($done)->toBeTrue();
    expect($content)->toBe('最终分析结果');
});

test('streamChat throws when all keys fail', function () {
    // Point config at an unreachable host
    $configRef = new ReflectionClass(\Config::class);
    $dataProp = $configRef->getProperty('data');
    $data = $dataProp->getValue();
    $data['ai']['baseUrl'] = 'http://127.0.0.1:1/unreachable';
    $dataProp->setValue(null, $data);

    try {
        AIClient::streamChat(
            [['role' => 'user', 'content' => 'hi']],
            [],
            function () {
            },
            function () {
            },
            function () {
            },
            function () {
            }
        );
        expect(true)->toBeFalse();
    } catch (\Exception $e) {
        expect($e->getMessage())->toContain('所有 API Key 均尝试失败');
    }
});