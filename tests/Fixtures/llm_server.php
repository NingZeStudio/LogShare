<?php

$body = file_get_contents('php://input');
$req = json_decode($body, true);
$hasTools = !empty($req['tools']);
$hasToolResult = false;
foreach (($req['messages'] ?? []) as $m) {
    if (($m['role'] ?? '') === 'tool') {
        $hasToolResult = true;
        break;
    }
}

// ---- scenario markers injected via the user message text ----
if (str_contains($body, 'NONSTREAM_FALLBACK_TEST')) {
    // Gateway ignored stream=true and answered with a one-shot JSON body
    header('Content-Type: application/json');
    echo json_encode([
        'id' => 'mock-nonstream',
        'object' => 'chat.completion',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'reasoning_content' => '一次性思考',
                'content' => '非流式完整回复',
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3],
    ], JSON_UNESCAPED_UNICODE);
    return;
}

if (str_contains($body, 'NO_SPACE_SSE_TEST')) {
    header('Content-Type: text/event-stream');
    echo "data:{\"choices\":[{\"delta\":{\"content\":\"无空格流\"},\"finish_reason\":null}]}\n\n";
    echo "data:[DONE]\n\n";
    return;
}

if (str_contains($body, 'STREAM_ERROR_FRAME_TEST')) {
    // HTTP 200 with an in-stream error frame
    header('Content-Type: text/event-stream');
    echo "data: {\"error\":{\"message\":\"上下文长度超出限制\",\"type\":\"invalid_request_error\"}}\n\n";
    return;
}

if (str_contains($body, 'EMPTY_STREAM_TEST')) {
    // HTTP 200 but nothing consumable at all
    header('Content-Type: text/event-stream');
    echo ": keep-alive\n\n";
    return;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

$emit = function (string $payload) {
    echo "data: " . $payload . "\n\n";
    flush();
};

if ($hasToolResult) {
    $emit(json_encode(['choices' => [['delta' => ['role' => 'assistant', 'reasoning_content' => '总结中', 'content' => ''], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE));
    $emit(json_encode(['choices' => [['delta' => ['content' => '最终分析结果'], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE));
    $emit(json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]]));
    $emit('[DONE]');
} elseif ($hasTools) {
    // Pick a tool name that the client actually registered, preferring rag_search
    $toolNames = array_column($req['tools'] ?? [], 'function');
    $toolNames = array_column($toolNames, 'name');
    $toolName = in_array('rag_search', $toolNames, true) ? 'rag_search' : 'web_search_exa';

    $emit(json_encode(['choices' => [['delta' => ['role' => 'assistant', 'reasoning_content' => '我需要检索', 'content' => ''], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE));
    $emit(json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => $toolName, 'arguments' => '{"query":"OutOfMemoryError']]]], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE));
    $emit(json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"']]]], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE));
    $emit(json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '}']]]], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE));
    $emit(json_encode(['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]]));
    $emit('[DONE]');
} else {
    $emit(json_encode(['choices' => [['delta' => ['role' => 'assistant', 'reasoning_content' => '思考中', 'content' => ''], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE));
    $emit(json_encode(['choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE));
    $emit(json_encode(['choices' => [['delta' => ['content' => ' World'], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE));
    $emit(json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]]));
    $emit('[DONE]');
}
