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
 *
 * K 取 16 而非业界默认 60：本库只有百级 chunk、每路至多召回 pool 条，K=60 时
 * 路内 rank0 与 rank1 只差 1.6%，头部区分度被压平；K=16 恢复约 6% 的边际差。
 * 语义通道权重 1.2：两路各自单点命中且名次相同时，历史实现会因「词法先插入 +
 * 稳定排序」把语义候选让位给 BM25，加权后该平局判给语义。
 */
final class RetrievalPipeline
{
    /** RRF 常数：本库量级下头部名次的边际收益拐点（见类注释） */
    public const RRF_K = 16;

    /** 各路召回融合权重；查询分类路由（Step 4）会按 topic 微调 */
    private const TYPE_WEIGHTS = ['lexical' => 1.0, 'vector' => 1.2];

    /**
     * 进入精排池时同一来源文件（source）的条数上限。
     *
     * patterns/*.md 每张诊断卡切成 4~6 个小节、小节标题同含主题词，BM25 标题
     * 权重 10 会让单个文件垄断词法候选位，把其他文件的语义候选挤出池外。
     * 上限 3 是「pool 30 ≈ 10 个来源」与「同主题多小节确实都相关」的折中。
     * 仅约束精排池：最终截断到 k 时不设限，同源是否全占 5 条由 cross-encoder 判断。
     */
    public const SOURCE_QUOTA = 3;

    public function __construct(private readonly RerankInterface $reranker)
    {
    }

    /**
     * 融合与精排的候选池上限：不低于历史实现 max(20, k*4)，并按精排器实际容量扩量。
     *
     * 精排器（rerank.maxCandidates，默认 30）的容量必须反映到池大小，否则配置的
     * 上限永远用不满；NoopReranker 返回 null 表示无容量，保持旧池尺寸。
     */
    public static function poolSize(int $k, ?int $maxCandidates = null): int
    {
        return max(20, $k * 4, $maxCandidates ?? 0);
    }

    /** 本管道在当前精排器配置下应使用的候选池上限。 */
    public function poolFor(int $k): int
    {
        return self::poolSize($k, $this->reranker->maxCandidates());
    }

    /**
     * 融合两路召回结果（均为 RagSearch 结果数组形态），按 RRF 分降序。
     *
     * 去重键与检索路径一致（source#title）。平局按「语义余弦 → BM25 → 键名字典序」
     * 三级裁决，与插入顺序无关（并列判给词法曾让语义通道系统性失效）。
     *
     * @param array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}> $lexical
     * @param array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}> $vector
     * @param array{rrfK?: int, weights?: array<string, float>}|null $opts 仅评测脚本做 A/B 用；线上恒为 null 取类常量
     * @return ScoredChunk[]
     */
    public static function fuse(array $lexical, array $vector, int $limit, ?array $opts = null): array
    {
        /** @var array<string, ScoredChunk> $byKey */
        $byKey = [];
        $rrfK = (int) ($opts['rrfK'] ?? self::RRF_K);
        $weights = $opts['weights'] ?? self::TYPE_WEIGHTS;

        // 语义路先插：让「两路各名单点命中」这类平局的原始归属清晰，
        // 平局本身由下面的三级比较器裁决，与插入顺序无关。
        foreach (['vector' => $vector, 'lexical' => $lexical] as $lane => $results) {
            $weight = (float) ($weights[$lane] ?? 1.0);
            foreach (array_slice($results, 0, $limit) as $rank => $result) {
                $key = $result['source'] . '#' . $result['title'];
                $contribution = $weight / ($rrfK + $rank + 1);
                if (isset($byKey[$key])) {
                    $existing = $byKey[$key];
                    // 同路重复只计一次分数（ranks 与分数都保留首次出现）
                    $isNewLane = !isset($existing->ranks[$lane]);
                    $takeLexical = $lane === 'lexical' && $isNewLane;
                    $takeVector = $lane === 'vector' && $isNewLane;
                    $byKey[$key] = new ScoredChunk(
                        // 载荷取词法路那份：result['score'] 是 bm25 原值，与改动前
                        // 融合结果的形态保持一致（只有顺序变了）
                        result: $takeLexical ? $result : $existing->result,
                        lexicalScore: $takeLexical ? self::lexicalScoreOf($result['score']) : $existing->lexicalScore,
                        vectorScore: $takeVector ? self::vectorScoreOf($result['score']) : $existing->vectorScore,
                        fusedScore: $existing->fusedScore + $contribution,
                        ranks: $existing->ranks + [$lane => $rank],
                    );
                    continue;
                }
                $byKey[$key] = new ScoredChunk(
                    result: $result,
                    lexicalScore: $lane === 'lexical' ? self::lexicalScoreOf($result['score']) : 0.0,
                    vectorScore: $lane === 'vector' ? self::vectorScoreOf($result['score']) : 0.0,
                    fusedScore: $contribution,
                    ranks: [$lane => $rank],
                );
            }
        }

        $chunks = array_values($byKey);
        usort($chunks, fn(ScoredChunk $a, ScoredChunk $b): int => $b->fusedScore <=> $a->fusedScore
            ?: $b->vectorScore <=> $a->vectorScore
            // BM25 是负向分值（越小越相关）
            ?: $a->lexicalScore <=> $b->lexicalScore
            ?: strcmp($a->key(), $b->key()));
        return $chunks;
    }

    /**
     * 按融合顺序保留每个 source 的最靠前 $quota 条，丢弃同源的更靠后条目。
     *
     * 多样性重排只在进入精排池之前调用（见 retrieve()），$quota <= 0 表示不限。
     *
     * @param ScoredChunk[] $chunks 已按 fusedScore 降序
     * @return ScoredChunk[]
     */
    public static function applySourceQuota(array $chunks, int $quota): array
    {
        if ($quota <= 0) {
            return $chunks;
        }
        $seen = [];
        $out = [];
        foreach ($chunks as $chunk) {
            $source = (string) $chunk->result['source'];
            $seen[$source] = ($seen[$source] ?? 0) + 1;
            if ($seen[$source] <= $quota) {
                $out[] = $chunk;
            }
        }
        return $out;
    }

    /**
     * 完整流程：融合 → 同源配额 → 截断到 pool → 精排（可能重排 pool 段）→ 取 top-k。
     *
     * 精排器抛任何异常都在此吞掉并回退 RRF 顺序——检索可用性优先。
     *
     * @param array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}> $lexical
     * @param array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}> $vector
     * @return array{results: array<int, array>, fused: int, reranked: bool}
     */
    public function retrieve(string $query, array $lexical, array $vector, int $k): array
    {
        $pool = $this->poolFor($k);
        $chunks = self::fuse($lexical, $vector, $pool);
        $diverse = self::applySourceQuota($chunks, self::SOURCE_QUOTA);

        $candidates = array_map(fn(ScoredChunk $c): array => $c->result, $diverse);
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

    /**
     * bm25 原值（负向，越小越相关）；LIKE 兜底的 'fallback' 等非数字返回 0。
     */
    private static function lexicalScoreOf(mixed $score): float
    {
        return is_numeric($score) ? (float) $score : 0.0;
    }
}
