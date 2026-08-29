<?php

use App\Client\AIClient;

beforeAll(function () {
    $server = startMockServer(CORE_PATH . '/tests/Fixtures/llm_server.php', 19100, 800);
    if ($server === null) {
        $GLOBALS['llm_base_url'] = null;
        $GLOBALS['llm_pid'] = null;
        return;
    }

    $GLOBALS['llm_base_url'] = $server['base'];
    $GLOBALS['llm_pid'] = $server['pid'];

    // Inject mock LLM endpoint into App\Config
    $configRef = new ReflectionClass(\App\Config::class);
    $dataProp = $configRef->getProperty('data');
    $data = $dataProp->getValue();
    $data['ai'] = [
        'apiKeys' => ['mock-key-1', 'mock-key-2'],
        'baseUrl' => $server['base'] . '/v1/chat/completions',
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
    skipWithoutMockServer($GLOBALS['llm_base_url']);
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
    skipWithoutMockServer($GLOBALS['llm_base_url']);
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
    expect(json_decode($call['arguments'], true))->toMatchArray(['query' => 'OutOfMemoryError']);
});

test('streamChat continues with tool result messages', function () {
    skipWithoutMockServer($GLOBALS['llm_base_url']);
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

test('streamChat consumes a non-streaming JSON body as a one-shot result', function () {
    skipWithoutMockServer($GLOBALS['llm_base_url']);
    $content = '';
    $reasoning = '';
    $done = false;

    AIClient::streamChat(
        [['role' => 'user', 'content' => 'NONSTREAM_FALLBACK_TEST']],
        [],
        function ($delta) use (&$content) {
            $content .= $delta;
        },
        function ($delta) use (&$reasoning) {
            $reasoning .= $delta;
        },
        function () {
        },
        function ($full, $hasTools) use (&$content, &$done) {
            $done = true;
        }
    );

    expect($reasoning)->toBe('一次性思考');
    expect($content)->toBe('非流式完整回复');
    expect($done)->toBeTrue();
});

test('streamChat accepts data: lines without the space separator', function () {
    skipWithoutMockServer($GLOBALS['llm_base_url']);
    $content = '';

    AIClient::streamChat(
        [['role' => 'user', 'content' => 'NO_SPACE_SSE_TEST']],
        [],
        function ($delta) use (&$content) {
            $content .= $delta;
        },
        function () {
        },
        function () {
        },
        function () {
        }
    );

    expect($content)->toBe('无空格流');
});

test('streamChat surfaces upstream in-stream error frames', function () {
    skipWithoutMockServer($GLOBALS['llm_base_url']);

    try {
        AIClient::streamChat(
            [['role' => 'user', 'content' => 'STREAM_ERROR_FRAME_TEST']],
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
        expect($e->getMessage())->toContain('上下文长度超出限制');
    }
});

test('streamChat throws on an HTTP 200 stream with no consumable data', function () {
    skipWithoutMockServer($GLOBALS['llm_base_url']);

    try {
        AIClient::streamChat(
            [['role' => 'user', 'content' => 'EMPTY_STREAM_TEST']],
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
        expect($e->getMessage())->toContain('返回空响应流');
    }
});

test('streamChat throws when all keys fail', function () {
    skipWithoutMockServer($GLOBALS['llm_base_url']);
    // Point config at an unreachable host
    $configRef = new ReflectionClass(\App\Config::class);
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
        expect($e->getMessage())->toContain('所有 AI API 密钥均尝试失败');
    }
});
