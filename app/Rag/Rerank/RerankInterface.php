<?php

namespace App\Rag\Rerank;

/**
 * 精排器接口：对融合后的候选列表做重排序。
 *
 * 输入输出均为 RagSearch 结果数组形态（title/body/source/score/snippet），
 * 实现方只能重排与截断，不得修改条目内容——检索管道其余环节依赖该形态。
 */
interface RerankInterface
{
    /**
     * 是否为真实精排实现（Noop 返回 false）。管道据此决定是否调用并标记
     * reranked 遥测——避免把「直通」误报为「已精排」。
     */
    public function isActive(): bool;

    /**
     * 精排器一次能处理的候选条数上限（ai.rag.rerank.maxCandidates 的生效值）。
     *
     * 融合管道据此决定候选池大小——配置的容量必须传导到池尺寸，否则精排永远
     * 只收到 max(20, k*4) 条。无容量概念的实现（Noop）返回 null。
     */
    public function maxCandidates(): ?int;

    /**
     * @param string $query 原始查询（供 LLM 相关性判断）
     * @param array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}> $candidates
     * @return array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}> 重排后的候选
     */
    public function rerank(string $query, array $candidates): array;
}
