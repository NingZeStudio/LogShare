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
    $emit(json_encode(['choices' => [['delta' => ['role' => 'assistant', 'reasoning_content' => '我需要搜索', 'content' => ''], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE));
    $emit(json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'web_search_exa', 'arguments' => '{"query":"minecraft']]]], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE));
    $emit(json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => ' crash"}']]]], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE));
    $emit(json_encode(['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]]));
    $emit('[DONE]');
} else {
    $emit(json_encode(['choices' => [['delta' => ['role' => 'assistant', 'reasoning_content' => '思考中', 'content' => ''], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE));
    $emit(json_encode(['choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE));
    $emit(json_encode(['choices' => [['delta' => ['content' => ' World'], 'finish_reason' => null]]], JSON_UNESCAPED_UNICODE));
    $emit(json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]]));
    $emit('[DONE]');
}