<?php

use App\Rag\RetrievalPipeline;
use App\Rag\Rerank\LLMReranker;
use App\Rag\Rerank\NoopReranker;
use App\Rag\ScoredChunk;

/** 构造 RagSearch 结果数组形态的候选 */
function rcp(string $source, string $title, mixed $score): array
{
    return ['title' => $title, 'body' => "body {$title}", 'source' => $source, 'score' => $score, 'snippet' => $title];
}

/* ─── RRF 融合 ───────────────────────────────────────────── */

test('RRF fusion sums reciprocal ranks across lanes and dedupes by source#title', function () {
    $lexical = [rcp('a.md', 'A', -1.5), rcp('b.md', 'B', -2.5), rcp('c.md', 'C', -3.0)];
    $vector = [rcp('c.md', 'C', 'vector:0.9'), rcp('a.md', 'A', 'vector:0.8')];

    $chunks = RetrievalPipeline::fuse($lexical, $vector, 20);

    // a: 1/61 + 1/62 ≈ .03252 > c: 1/63 + 1/61 ≈ .03227 > b: 1/62
    expect($chunks[0]->result['title'])->toBe('A');
    expect($chunks[0]->ranks)->toBe(['lexical' => 0, 'vector' => 1]);
    expect($chunks[0]->fusedScore)->toBeGreaterThan($chunks[1]->fusedScore);
    expect(count($chunks))->toBe(3, 'dedupe keeps single entry for a/c');

    // 双路命中（a/c）分数高于单路命中（b）
    $byKey = array_combine(array_map(fn(ScoredChunk $c) => $c->key(), $chunks), $chunks);
    expect($byKey['b.md#B']->fusedScore)->toBeLessThan($byKey['c.md#C']->fusedScore);
});

test('RRF lane truncation respects limit', function () {
    $lexical = array_map(fn($i) => rcp("f{$i}.md", "T{$i}", -1.0 * $i), range(1, 30));
    $chunks = RetrievalPipeline::fuse($lexical, [], 10);
    expect(count($chunks))->toBe(10);
});

test('vector score string parses into vectorScore field', function () {
    $chunks = RetrievalPipeline::fuse([], [rcp('v.md', 'V', 'vector:0.87')], 20);
    expect($chunks[0]->vectorScore)->toBe(0.87);
    expect($chunks[0]->lexicalScore)->toBe(0.0);
});

/* ─── 精排接线 ───────────────────────────────────────────── */

test('Noop reranker passes through and pipeline marks not reranked', function () {
    $pipeline = new RetrievalPipeline(new NoopReranker());
    $lexical = [rcp('a.md', 'A', -1.0), rcp('b.md', 'B', -2.0)];
    $out = $pipeline->retrieve('q', $lexical, [], 2);

    expect($out['reranked'])->toBeFalse();
    expect($out['fused'])->toBe(2);
    expect(array_column($out['results'], 'title'))->toBe(['A', 'B']);
});

test('pipeline k truncation and pool reporting', function () {
    $pipeline = new RetrievalPipeline(new NoopReranker());
    $lexical = array_map(fn($i) => rcp("f{$i}.md", "T{$i}", -1.0 * $i), range(1, 8));
    $out = $pipeline->retrieve('q', $lexical, [], 3);
    expect($out['results'])->toHaveCount(3);
    expect($out['fused'])->toBe(8);
});

/* ─── LLMReranker 纯逻辑部分 ─────────────────────────────── */

test('parseOrder extracts deduped 1-based sequence', function () {
    expect(LLMReranker::parseOrder('3,1,2', 3))->toBe([2, 0, 1]);
    expect(LLMReranker::parseOrder("排名：2 > 1", 2))->toBe([1, 0]);
    expect(LLMReranker::parseOrder('2,2,1', 2))->toBe([1, 0], 'duplicates dropped');
    expect(LLMReranker::parseOrder('99', 3))->toBeNull('out-of-range only → failure');
    expect(LLMReranker::parseOrder('无法判断', 3))->toBeNull();
    expect(LLMReranker::parseOrder('1', 2))->toBeNull('single index not enough');
});

test('LLMReranker fails open when AI gateway unavailable', function () {
    // 未配置 apiKeys：streamChat 抛异常 → rerank 原样返回输入
    $cfgRef = new ReflectionClass(\App\Config::class);
    $dataProp = $cfgRef->getProperty('data');
    $orig = $dataProp->getValue();
    $data = $orig;
    $data['ai']['apiKeys'] = [];
    unset($data['ai']['apiKey']);
    $dataProp->setValue(null, $data);

    $candidates = [rcp('a.md', 'A', 1), rcp('b.md', 'B', 2)];
    $reranker = new LLMReranker();
    expect($reranker->isActive())->toBeTrue();
    expect($reranker->rerank('q', $candidates))->toBe($candidates);

    $dataProp->setValue(null, $orig);
});

test('LLMReranker returns input untouched below two candidates', function () {
    $one = [rcp('a.md', 'A', 1)];
    expect((new LLMReranker())->rerank('q', $one))->toBe($one);
    expect((new LLMReranker())->rerank('q', []))->toBe([]);
});

/* ─── 默认路径行为保持 ───────────────────────────────────── */

test('rerankEnabled defaults to false when config absent', function () {
    expect(\App\Rag\RagSearch::rerankEnabled())->toBeFalse();
});
