<?php

namespace App\Rag;

/**
 * RRF 融合后的候选块：记录两路召回分与融合分，供精排与观测使用。
 *
 * lexicalScore 是 bm25 原始分（越小越好，保留原值仅做观测），
 * vectorScore 是余弦相似度（0-1），fusedScore 是 RRF 加权和（越大越好）。
 */
final class ScoredChunk
{
    /**
     * @param array{title: string, body: string, source: string, score: mixed, snippet: string} $result
     * @param array<string, int> $ranks 各路召回的排名（lexical / vector，0 起）
     */
    public function __construct(
        public readonly array $result,
        public readonly float $lexicalScore,
        public readonly float $vectorScore,
        public readonly float $fusedScore,
        public array $ranks = [],
    ) {
    }

    public function key(): string
    {
        return $this->result['source'] . '#' . $this->result['title'];
    }
}
