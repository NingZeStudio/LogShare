<?php

declare(strict_types=1);

namespace App\Agent\Llm;

/**
 * 一轮 LLM 流式调用的回调接收端（对齐 AIClient::streamChat 的回调契约）。
 *
 * 单独抽出接口是为了让 AgentRuntime 依赖抽象而非具体 AIClient：
 * 生产用 AIClientGateway 转调 AIClient，单测用 RecordingHandler 断言帧序列，
 * 从而在无 Swoole 环境下验证多轮 tool loop 行为。
 */
interface LlmStreamHandler
{
    /** 正文增量（逐 token），用于 SSE content 帧 */
    public function onContentDelta(string $delta): void;

    /** 思维链增量，用于 SSE thinking 帧 */
    public function onReasoningDelta(string $reasoning): void;

    /** 本轮结束收集到的 tool_calls（[{id,name,arguments}]）与聚合 reasoning */
    public function onToolCalls(array $toolCalls, string $reasoning): void;

    /**
     * 网关以「整段内容」而非增量返回时的最终正文（覆盖已累加的 content）。
     * 非流式回退路径使用，避免与 onContentDelta 重复计数。
     */
    public function onFullContent(string $content): void;
}
