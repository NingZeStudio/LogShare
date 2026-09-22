<?php

declare(strict_types=1);

namespace App\Agent\Tool;

/**
 * 一次工具调用的最终结果（不可变值对象）。
 *
 * content 为回传给模型的文本（与 LogAgent 现有 executeTool 的字符串返回一致）；
 * 元数据供 AnalysisTracer / ToolSession 记录链路使用，不进入模型消息。
 */
final class ToolResult
{
    private function __construct(
        public readonly string $content,
        public readonly bool $ok,
        /** 实际产出该结果的工具名（fallback 命中时与请求名不同） */
        public readonly string $producedBy,
        public readonly bool $retried,
        public readonly int $attempts,
        public readonly float $durationMs,
        public readonly ?string $error = null,
    ) {
    }

    public static function success(string $content, string $producedBy, bool $retried = false, int $attempts = 1, float $durationMs = 0.0): self
    {
        return new self($content, true, $producedBy, $retried, $attempts, $durationMs);
    }

    public static function failure(string $content, string $producedBy, bool $retried = false, int $attempts = 1, float $durationMs = 0.0, ?string $error = null): self
    {
        return new self($content, false, $producedBy, $retried, $attempts, $durationMs, $error);
    }
}
