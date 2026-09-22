<?php

declare(strict_types=1);

namespace App\Client;

/**
 * 一轮流式补全归并后的响应值对象（对齐 plan.md §3.9）。
 *
 * 承载 SseParser 累积出的最终文本、思维链与已归一的 tool_calls，替代此前散落的
 * $fullContent / $fullReasoning / $toolCalls 三个引用变量，让 streamChat 收尾更清晰。
 * tool_calls 已由 SseParser::toolCalls() 完成空 name 过滤与 arguments 归一。
 */
final class ChatResponse
{
    /**
     * @param array<int, array<string, mixed>> $toolCalls 归一后的工具调用（可能为空）
     */
    public function __construct(
        public readonly string $content,
        public readonly string $reasoning,
        public readonly array $toolCalls = [],
    ) {
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
