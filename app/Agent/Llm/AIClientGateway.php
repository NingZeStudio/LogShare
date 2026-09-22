<?php

declare(strict_types=1);

namespace App\Agent\Llm;

use App\Client\AIClient;

/**
 * AIClient 适配器：将 LlmGateway 接口桥接到 AIClient::streamChat。
 *
 * AIClient 使用四个回调（onDelta/onReasoning/onToolCalls/onDone），
 * LlmGateway 使用 LlmStreamHandler 接口。本类负责将 handler 的方法
 * 映射到对应的回调参数，使 AgentRuntime 可以通过统一的 LlmGateway
 * 接口调用 AIClient。
 */
final class AIClientGateway implements LlmGateway
{
    /**
     * 流式调用 AIClient，将结果转发到 handler。
     *
     * @param array $messages 消息列表
     * @param array $tools 工具定义
     * @param LlmStreamHandler $handler 流式回调处理器
     */
    public function stream(array $messages, array $tools, LlmStreamHandler $handler): void
    {
        AIClient::streamChat(
            $messages,
            $tools,
            // onDelta: 内容增量
            function (string $delta) use ($handler): void {
                $handler->onContentDelta($delta);
            },
            // onReasoning: 推理过程增量
            function (string $reasoning) use ($handler): void {
                $handler->onReasoningDelta($reasoning);
            },
            // onToolCalls: 工具调用列表 + 推理过程
            function (array $toolCalls, string $reasoning) use ($handler): void {
                $handler->onToolCalls($toolCalls, $reasoning);
            },
            // onDone: 完整内容 + 是否有工具调用
            function (string $fullContent, bool $hasToolCalls) use ($handler): void {
                $handler->onFullContent($fullContent);
            }
        );
    }
}
