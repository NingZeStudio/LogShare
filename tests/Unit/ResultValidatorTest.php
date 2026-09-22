<?php

use App\Agent\ResultValidator;
use App\Agent\ToolSession;

/**
 * 验收「结构化输出」契约（plan.md §3.6 / §八）：
 * 无论模型是否按 schema 输出 JSON，validation.structuredOutput 恒为
 * {rootCause, confidence, evidence[], steps[]} 四字段。
 */
test('structured output parses the trailing json block verbatim', function () {
    $answer = "分析正文……\n\n```json\n{\"rootCause\":\"Mixin 注入失败\",\"confidence\":0.82,\"evidence\":[\"Caused by: MixinApplyError\"],\"steps\":[\"更新 mixin 版本\",\"重启\"]}\n```";
    $v = (new ResultValidator())->validate($answer, new ToolSession());

    $so = $v->structuredOutput;
    expect($so)->toHaveKeys(['rootCause', 'confidence', 'evidence', 'steps']);
    expect($so['rootCause'])->toBe('Mixin 注入失败');
    expect($so['confidence'])->toBe(0.82);
    expect($so['evidence'])->toBe(['Caused by: MixinApplyError']);
    expect($so['steps'])->toBe(['更新 mixin 版本', '重启']);
});

test('structured output falls back to prose heuristics when no json block', function () {
    $answer = "根因：内存不足导致 OOM。\n解决方案：\n1. 调大堆内存\n2. 关闭部分模组";
    $v = (new ResultValidator())->validate($answer, new ToolSession());

    $so = $v->structuredOutput;
    expect($so['rootCause'])->toBe('内存不足导致 OOM。');
    expect($so['evidence'])->toBe([]);
    expect($so['steps'])->toBe(['调大堆内存', '关闭部分模组']);
    // 无证据但有根因 → 低置信度，且始终为 0..1 的数值
    expect($so['confidence'])->toBe(0.4);
});

test('confidence is clamped into 0..1 from json', function () {
    $answer = "```json\n{\"rootCause\":\"x\",\"confidence\":1.7,\"evidence\":[],\"steps\":[]}\n```";
    $v = (new ResultValidator())->validate($answer, new ToolSession());

    expect($v->structuredOutput['confidence'])->toBe(1.0);
});
