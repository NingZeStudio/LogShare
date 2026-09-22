<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * 一次完整分析的结果值对象（对齐 plan.md §3.3 AnalysisResult）。
 *
 * 由 AgentRuntime 产出：既保留向后兼容的 fullAnswer 纯文本，又携带结构化的
 * 调用链、评分与校验结论，供上层写缓存 / SSE 收尾 / 埋点使用。
 */
final class AnalysisResult
{
    /**
     * @param array<int, array<string, mixed>> $toolCallChain
     * @param array<string, mixed>             $metrics
     * @param array<string, mixed>             $validation
     */
    public function __construct(
        public readonly string $fullAnswer,
        public readonly bool $success,
        public readonly int $rounds,
        public readonly array $toolCallChain,
        public readonly ?string $cacheKey,
        public readonly array $metrics = [],
        public readonly ?array $validation = null,
        public readonly ?array $score = null,
    ) {
    }

    /** 是否产出可写缓存的有效答案（成功且非空） */
    public function cacheable(): bool
    {
        return $this->success && $this->fullAnswer !== '';
    }
}
