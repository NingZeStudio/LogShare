<?php

declare(strict_types=1);

namespace App\Rag;

use App\Rag\Rerank\RerankInterface;

/**
 * 检索融合管道：多路召回（词法 + 向量）→ RRF 融合 → 可选精排。
 *
 * 召回本身由调用方完成后注入（向量召回需要先 embed query，网络职责留在
 * RagSearch 侧），本类只做纯计算：便于脱离 IO 单测融合与精排接线。
 *
 * RRF（Reciprocal Rank Fusion）：score = Σ typeWeight_i / (RRF_K + rank_i)。
 * BM25 与余弦分数量纲不同（10+ vs 0-1），加权平均对尺度敏感，RRF 只看
 * 排名位置，天然免疫。
 */
final class RetrievalPipeline
{
    /** RRF 常数：头部名次的边际收益拐点，业界默认 60 */
    public const RRF_K = 60;

    /** 各路召回融合权重；查询分类路由（Step 4）会按 topic 微调 */
    private const TYPE_WEIGHTS = ['lexical' => 1.0, 'vector' => 1.0];

    public function __construct(private readonly RerankInterface $reranker)
    {
    }

    /**
     * 融合两路召回结果（均为 RagSearch 结果数组形态），按 RRF 分降序。
     *
     * 去重键与检索路径一致（source#title）。并列分时词法优先（插入序稳定）。
     *
     * @param array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}> $lexical
     * @param array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}> $vector
     * @return ScoredChunk[]
     */
    public static function fuse(array $lexical, array $vector, int $limit): array
    {
        /** @var array<string, ScoredChunk> $byKey */
        $byKey = [];

        foreach (['lexical' => $lexical, 'vector' => $vector] as $lane => $results) {
            $weight = self::TYPE_WEIGHTS[$lane];
            foreach (array_slice($results, 0, $limit) as $rank => $result) {
                $key = $result['source'] . '#' . $result['title'];
                $contribution = $weight / (self::RRF_K + $rank + 1);
                if (isset($byKey[$key])) {
                    $existing = $byKey[$key];
                    $byKey[$key] = new ScoredChunk(
                        result: $existing->result,
                        lexicalScore: $existing->lexicalScore,
                        vectorScore: $existing->vectorScore,
                        fusedScore: $existing->fusedScore + $contribution,
                        ranks: $existing->ranks + [$lane => $rank],
                    );
                    continue;
                }
                $byKey[$key] = new ScoredChunk(
                    result: $result,
                    lexicalScore: $lane === 'lexical' ? (float) (is_numeric($result['score']) ? $result['score'] : 0) : 0.0,
                    vectorScore: $lane === 'vector' ? self::vectorScoreOf($result['score']) : 0.0,
                    fusedScore: $contribution,
                    ranks: [$lane => $rank],
                );
            }
        }

        $chunks = array_values($byKey);
        usort($chunks, fn(ScoredChunk $a, ScoredChunk $b): int => $b->fusedScore <=> $a->fusedScore);
        return $chunks;
    }

    /**
     * 完整流程：融合 → 截断到 pool → 精排（可能重排 pool 段）→ 取 top-k。
     *
     * 精排器抛任何异常都在此吞掉并回退 RRF 顺序——检索可用性优先。
     *
     * @param array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}> $lexical
     * @param array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}> $vector
     * @return array{results: array<int, array>, fused: int, reranked: bool}
     */
    public function retrieve(string $query, array $lexical, array $vector, int $k): array
    {
        $pool = max(20, $k * 4);
        $chunks = self::fuse($lexical, $vector, $pool);

        $candidates = array_map(fn(ScoredChunk $c): array => $c->result, $chunks);
        $rerankInput = array_slice($candidates, 0, $pool);
        $rerankOrder = null;
        if ($this->reranker->isActive() && count($rerankInput) >= 2) {
            $rerankOrder = $this->tryRerank($query, $rerankInput);
        }

        $final = $rerankOrder ?? $candidates;
        return [
            'results' => array_slice($final, 0, $k),
            'fused' => count($chunks),
            'reranked' => $rerankOrder !== null,
        ];
    }

    /**
     * @param array<int, array> $candidates
     * @return array<int, array>|null 精排失败返回 null（保持 RRF 顺序）
     */
    private function tryRerank(string $query, array $candidates): ?array
    {
        try {
            $reranked = $this->reranker->rerank($query, $candidates);
        } catch (\Throwable $e) {
            \App\Syslog::error('RAG', 'reranker threw, keeping RRF order: ' . $e->getMessage());
            return null;
        }
        // 防御实现违约：条目数/集合变了视为失败（如 Noop 之外的实现漏了原样返回）
        if (count($reranked) !== count($candidates)) {
            \App\Syslog::error('RAG', 'reranker changed candidate count, keeping RRF order');
            return null;
        }
        return $reranked;
    }

    /**
     * 'vector:0.87' → 0.87；其余形态返回 0。
     */
    private static function vectorScoreOf(mixed $score): float
    {
        if (is_string($score) && str_starts_with($score, 'vector:')) {
            return (float) substr($score, 7);
        }
        return is_numeric($score) ? (float) $score : 0.0;
    }
}
