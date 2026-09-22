<?php

declare(strict_types=1);

namespace App\Agent\Llm;

/**
 * LLM 流式调用的传输抽象。
 *
 * stream() 语义与 AIClient::streamChat 一致：给定 messages + tools，
 * 通过 handler 增量回调 content/reasoning，并在本轮收集到工具调用时回调
 * onToolCalls。把该契约显式化，AgentRuntime 即可注入替身实现在纯 PHP 下测试。
 */
interface LlmGateway
{
    /**
     * @param array<int, array<string, mixed>> $messages OpenAI 风格消息列表
     * @param array<int, array<string, mixed>> $tools    function-calling 工具定义
     */
    public function stream(array $messages, array $tools, LlmStreamHandler $handler): void;
}
