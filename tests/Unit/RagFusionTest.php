<?php

use App\Rag\RagSearch;
use App\Rag\RetrievalPipeline;
use App\Rag\Rerank\RerankInterface;

/**
 * RetrievalPipeline 融合排序回归锁。
 *
 * 纯计算用例：两路召回由测试构造，不碰 SQLite 索引也不发网络请求，
 * 因此可以进 CI —— 线上排序质量的金标评测在 scripts/rag_eval.php。
 */

/** 记录型精排器：容量可控、顺序反转可观察。 */
final class RecordingReranker implements RerankInterface
{
    /** @var array<int, array> */
    public array $received = [];

    public function __construct(private readonly ?int $capacity = null)
    {
    }

    public function isActive(): bool
    {
        return true;
    }

    public function maxCandidates(): ?int
    {
        return $this->capacity;
    }

    public function rerank(string $query, array $candidates): array
    {
        $this->received = $candidates;
        return array_reverse($candidates);
    }
}

/** @return array{title: string, body: string, source: string, score: mixed, snippet: string} */
function fusionRow(string $source, string $title, mixed $score): array
{
    return ['title' => $title, 'body' => $title . ' 正文', 'source' => $source, 'score' => $score, 'snippet' => $title];
}

/** 取融合结果的 source 序列。 */
function fuseSources(array $lexical, array $vector, int $limit = 20, ?array $opts = null): array
{
    return array_column(array_map(fn($c) => $c->result, RetrievalPipeline::fuse($lexical, $vector, $limit, $opts)), 'source');
}

/* ─── 平局裁决：语义通道不再被让位给 BM25 ─────────────────── */

test('tie is broken toward the vector lane, not insertion order', function () {
    // 两路各自 rank0：RRF 贡献完全相等（等权重下均为 1/17）。
    // 历史实现「词法先插入 + 稳定排序」把语义候选挤到第 2 位。
    $equalWeights = ['weights' => ['lexical' => 1.0, 'vector' => 1.0]];
    $order = fuseSources(
        [fusionRow('lexical-only.md', '词法命中', -12.5)],
        [fusionRow('vector-only.md', '语义命中', 'vector:0.90')],
        20,
        $equalWeights,
    );

    expect($order[0])->toBe('vector-only.md');
});

test('tie-break reaches the bm25 level when cosine is absent on both sides', function () {
    // 融合分与 vectorScore 全部相等，只剩 bm25 可比较：-12 比 0 更相关。
    // 词法项在 vector 项之后插入，故该结果只能来自比较器而非插入顺序。
    $order = fuseSources(
        [fusionRow('better-bm25.md', '词法命中', -12.0)],
        [fusionRow('zero-cosine.md', '语义命中', 'vector:0.0')],
        20,
        ['weights' => ['lexical' => 1.0, 'vector' => 1.0]],
    );

    expect($order[0])->toBe('better-bm25.md');
});

test('tie-break is total: identical scores fall back to the key', function () {
    // 三路分数皆等（bm25=0、cosine=0），最终由 source#title 字典序定序，
    // 与插入顺序（vector 先）无关。
    $order = fuseSources(
        [fusionRow('a-first.md', '词法命中', 0)],
        [fusionRow('z-later.md', '语义命中', 'vector:0.0')],
        20,
        ['weights' => ['lexical' => 1.0, 'vector' => 1.0]],
    );

    expect($order)->toBe(['a-first.md', 'z-later.md']);
});

test('equal fused scores are decided by cosine even when insertion favours the other side', function () {
    // A 占向量 rank0、B 占向量 rank1（与余弦高低刻意相反），两路名次互补使融合分相等。
    // 插入顺序偏向 A，因此 B 胜出只能来自 vectorScore 这一级比较。
    $chunks = RetrievalPipeline::fuse(
        [fusionRow('b.md', '跨路命中', -5.0), fusionRow('a.md', '跨路命中', -4.0)],
        [fusionRow('a.md', '跨路命中', 'vector:0.30'), fusionRow('b.md', '跨路命中', 'vector:0.90')],
        20,
        ['weights' => ['lexical' => 1.0, 'vector' => 1.0]],
    );

    expect($chunks[0]->result['source'])->toBe('b.md')
        ->and($chunks[0]->fusedScore)->toBe($chunks[1]->fusedScore)
        // 两路分数都要留在 ScoredChunk 上：跨路命中不得只剩一路的分
        ->and($chunks[0]->vectorScore)->toBe(0.9)
        ->and($chunks[0]->lexicalScore)->toBe(-5.0)
        ->and($chunks[0]->ranks)->toBe(['vector' => 1, 'lexical' => 0]);
});

/* ─── 权重与 RRF_K 的量级 ──────────────────────────────── */

test('vector lane carries more weight than the lexical lane', function () {
    $chunks = RetrievalPipeline::fuse(
        [fusionRow('lex.md', '词法', -12.5)],
        [fusionRow('vec.md', '语义', 'vector:0.90')],
        20,
    );

    $bySource = [];
    foreach ($chunks as $c) {
        $bySource[$c->result['source']] = $c->fusedScore;
    }
    expect($bySource['vec.md'] / $bySource['lex.md'])->toBeGreaterThan(1.19)
        ->toBeLessThan(1.21);
});

test('head rank discrimination recovers at RRF_K 16', function () {
    $gap = fn(int $k): float => RetrievalPipeline::fuse([], [
        fusionRow('r0.md', '首位', 'vector:0.9'),
        fusionRow('r1.md', '次位', 'vector:0.8'),
    ], 20, ['rrfK' => $k])[0]->fusedScore
        / RetrievalPipeline::fuse([], [
            fusionRow('r0.md', '首位', 'vector:0.9'),
            fusionRow('r1.md', '次位', 'vector:0.8'),
        ], 20, ['rrfK' => $k])[1]->fusedScore;

    expect(RetrievalPipeline::RRF_K)->toBe(16)
        // K=60 时首位/次位只差 1.6%，头部名次被压平；K=16 恢复约 6% 边际
        ->and($gap(16))->toBeGreaterThan(1.05)
        ->and($gap(60))->toBeLessThan(1.02);
});

/* ─── 同源配额：只约束精排池 ───────────────────────────── */

test('source quota caps one file inside the rerank pool without touching the fused count', function () {
    $chunks = RetrievalPipeline::fuse([
        fusionRow('垄断.md', '小节一', -9.0),
        fusionRow('垄断.md', '小节二', -8.0),
        fusionRow('垄断.md', '小节三', -7.0),
        fusionRow('垄断.md', '小节四', -6.0),
        fusionRow('垄断.md', '小节五', -5.0),
        fusionRow('其他.md', '别的文件', -4.0),
    ], [], 20);

    expect(count($chunks))->toBe(6);

    $quota = RetrievalPipeline::applySourceQuota($chunks, RetrievalPipeline::SOURCE_QUOTA);
    $perSource = [];
    foreach ($quota as $c) {
        $perSource[$c->result['source']] = ($perSource[$c->result['source']] ?? 0) + 1;
    }

    expect($perSource)->toBe(['垄断.md' => 3, '其他.md' => 1])
        // 保留的是该 source 名次最靠前的几条，不是任意三条
        ->and($quota[0]->result['title'])->toBe('小节一');
});

test('quota unlimited when disabled', function () {
    $chunks = RetrievalPipeline::fuse([
        fusionRow('s.md', '甲', -3.0),
        fusionRow('s.md', '乙', -2.0),
        fusionRow('s.md', '丙', -1.0),
    ], [], 20);

    expect(count(RetrievalPipeline::applySourceQuota($chunks, 0)))->toBe(3)
        ->and(count(RetrievalPipeline::applySourceQuota($chunks, -1)))->toBe(3);
});

test('retrieve reports pre-quota fused count and caps each source inside the pool', function () {
    $lexical = [];
    for ($i = 0; $i < 8; $i++) {
        $lexical[] = fusionRow('same.md', '小节' . $i, -10.0 + $i);
    }
    $lexical[] = fusionRow('other.md', '别家一', -2.5);
    $lexical[] = fusionRow('other.md', '别家二', -1.5);
    $pipeline = new RetrievalPipeline(new RecordingReranker(30));

    $r = $pipeline->retrieve('q', $lexical, [], 5);

    expect($r['fused'])->toBe(10)
        ->and($r['reranked'])->toBeTrue()
        ->and(count($r['results']))->toBe(5)
        // 同源 8 条被夹到 3 条：垄断文件的靠后小节不得挤占池位
        ->and(count(array_filter($r['results'], fn($x) => $x['source'] === 'same.md')))->toBe(3);
});

/* ─── 精排容量传导到候选池 ─────────────────────────────── */

test('pool size honours the reranker capacity', function () {
    expect(RetrievalPipeline::poolSize(5))->toBe(20)
        ->and(RetrievalPipeline::poolSize(5, 30))->toBe(30)
        ->and(RetrievalPipeline::poolSize(8, 30))->toBe(32)
        ->and(RetrievalPipeline::poolSize(8, 20))->toBe(32);

    expect((new RetrievalPipeline(new RecordingReranker(30)))->poolFor(5))->toBe(30)
        ->and((new RetrievalPipeline(new RecordingReranker(null)))->poolFor(5))->toBe(20);
});

test('retrieve feeds the reranker the full configured pool', function () {
    $reranker = new RecordingReranker(30);
    $lexical = [];
    for ($i = 0; $i < 40; $i++) {
        $lexical[] = fusionRow('src' . $i . '.md', '标题' . $i, -40.0 + $i);
    }

    $r = (new RetrievalPipeline($reranker))->retrieve('q', $lexical, [], 5);

    expect(count($reranker->received))->toBe(30)
        // 精排结果确实替换了 RRF 顺序
        ->and($r['results'][0]['source'])->toBe($reranker->received[count($reranker->received) - 1]['source']);
});

test('a reranker without capacity keeps the legacy pool of twenty', function () {
    $reranker = new RecordingReranker(null);
    $lexical = [];
    for ($i = 0; $i < 40; $i++) {
        $lexical[] = fusionRow('src' . $i . '.md', '标题' . $i, -40.0 + $i);
    }

    (new RetrievalPipeline($reranker))->retrieve('q', $lexical, [], 5);

    expect(count($reranker->received))->toBe(20);
});

/* ─── 禁用态行为不变（ai.rag.* 默认关闭即逐字节不变） ────── */

test('disabled rerank reports no capacity so the pool stays at the legacy width', function () {
    $cfgRef = new ReflectionClass(\App\Config::class);
    $dataProp = $cfgRef->getProperty('data');
    $orig = $dataProp->getValue();

    $write = function (array $rerank) use ($dataProp, $orig) {
        $data = $orig;
        $data['ai']['rag']['rerank'] = $rerank;
        $dataProp->setValue(null, $data);
    };

    try {
        $write(['enabled' => false, 'maxCandidates' => 30]);
        expect(RagSearch::rerankMaxCandidates())->toBeNull()
            ->and(RetrievalPipeline::poolSize(5, RagSearch::rerankMaxCandidates()))->toBe(20);

        $write(['enabled' => true, 'maxCandidates' => 30]);
        expect(RagSearch::rerankMaxCandidates())->toBe(30)
            ->and(RetrievalPipeline::poolSize(5, RagSearch::rerankMaxCandidates()))->toBe(30);

        // 明显笔误（0/1）不再静默夹成 2，而是退回默认 30 并告警
        $write(['enabled' => true, 'maxCandidates' => 1]);
        expect(RagSearch::rerankMaxCandidates())->toBe(30);

        // 越上界同样退回默认：50 是硬上限之上的配置错误，不是「要更多候选」
        $write(['enabled' => true, 'maxCandidates' => 500]);
        expect(RagSearch::rerankMaxCandidates())->toBe(30);

        $write(['enabled' => true]);
        expect(RagSearch::rerankMaxCandidates())->toBe(30);
    } finally {
        $dataProp->setValue(null, $orig);
    }
});
