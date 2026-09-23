<?php

declare(strict_types=1);

/**
 * RAG 召回质量离线评测：对金标集做融合排序参数的 A/B。
 *
 * 只跑检索计算，不起服务也不发业务请求：词法走 LexicalIndex、向量走 VectorIndex
 * 对 doc_embeddings 里真实 packed 向量算余弦、融合走 RetrievalPipeline，与线上同码。
 * 查询向量按「provider 指纹 + query」缓存到 runtime/rag_eval_emb.json，首轮联网
 * embedding 之后反复调参数不再消耗配额；无网络时用 --offline 只吃缓存。
 *
 * 用法：
 *   php scripts/rag_eval.php                      # base（改动前）与 k16w12q3（现码）对照
 *   php scripts/rag_eval.php --variant=k16w12     # 只跑单个变体
 *   php scripts/rag_eval.php --variant=all        # 全部变体
 *   php scripts/rag_eval.php --pool=20            # 强制各变体候选池，隔离配额与扩量的影响
 *   php scripts/rag_eval.php --rerank             # 追加 live 变体：线上配置的精排（联网）
 *   php scripts/rag_eval.php --offline            # 不联网：缺缓存向量的用例直接跳过
 *
 * 指标口径（top5 上计算，expect 是可接受来源集合、不分先后）：
 *   hit@1/3/5  前 N 条内命中任一 expect 的用例比例
 *   MRR        首个命中排名的倒数均值
 *   mono       top5 中同一来源的最大条数（单文件垄断度；patterns 小节挤占全部位次即由它暴露）
 *   alive      向量通道 top1 仍留在 top5 的比例（融合与配额把它挤出去多少）
 *
 * 不进 CI：依赖在线 embedding，结果不确定。CI 侧的排序回归由
 * tests/Unit/RagFusionTest.php 的纯计算用例承担。
 *
 * 退出码：金标集或索引缺失、embedding 不可用 → 2；
 * 任一变体的 paraphrase hit@5 低于 base → 1（改动不得牺牲纯语义召回）。
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Rag\LexicalIndex;
use App\Rag\RagSearch;
use App\Rag\RetrievalPipeline;
use App\Rag\VectorIndex;

/**
 * 变体定义：merge=legacy 是 rerank 关闭时线上实际走的「向量优先 + 去重 + 截断」合并，
 * 作为最低参照；其余为 RRF 融合，逐步叠加 RRF_K、语义权重、同源配额与池扩量。
 *
 * 数值是评测刻度而非线上真相：SOURCE_QUOTA 日后调整时，历史变体名仍指原来的量，
 * 要测新值请另加一个变体。
 */
const EVAL_VARIANTS = [
    'legacy' => ['merge' => 'legacy', 'rrfK' => 60, 'vectorWeight' => 1.0, 'quota' => 0, 'pool' => 20],
    'base' => ['merge' => 'rrf', 'rrfK' => 60, 'vectorWeight' => 1.0, 'quota' => 0, 'pool' => 20],
    'k16' => ['merge' => 'rrf', 'rrfK' => 16, 'vectorWeight' => 1.0, 'quota' => 0, 'pool' => 20],
    'k16w12' => ['merge' => 'rrf', 'rrfK' => 16, 'vectorWeight' => 1.2, 'quota' => 0, 'pool' => 20],
    // 现网实现：K=16 + 语义权重 1.2 + 同源配额 3 + 池按 rerank.maxCandidates 扩到 30
    'k16w12q3' => ['merge' => 'rrf', 'rrfK' => 16, 'vectorWeight' => 1.2, 'quota' => 3, 'pool' => 30],
];

$options = getopt('', ['variant:', 'pool:', 'k:', 'gold:', 'rerank', 'offline', 'help']);
if (isset($options['help'])) {
    echo "用法：php scripts/rag_eval.php [--variant=base|legacy|k16|k16w12|k16w12q3|head|all] [--pool=N] [--k=N] [--rerank] [--offline]\n";
    exit(0);
}

$root = dirname(__DIR__);
$k = max(1, min(20, (int) ($options['k'] ?? 5)));
$forcePool = isset($options['pool']) ? max(2, (int) $options['pool']) : null;
$wantRerank = isset($options['rerank']);
$offline = isset($options['offline']);
$goldPath = (string) ($options['gold'] ?? $root . '/tests/Fixtures/rag_gold_set.json');

$variantNames = [];
foreach ((array) ($options['variant'] ?? 'base,k16w12q3') as $name) {
    foreach (array_map('trim', explode(',', (string) $name)) as $one) {
        if ($one === '') {
            continue;
        }
        $variantNames[] = $one === 'head' ? 'k16w12q3' : $one;
    }
}
$variantNames = array_values(array_unique($variantNames));
if ($variantNames === []) {
    $variantNames = ['base', 'k16w12q3'];
}
if (in_array('all', $variantNames, true)) {
    $variantNames = array_keys(EVAL_VARIANTS);
}
$unknown = array_diff($variantNames, array_keys(EVAL_VARIANTS));
if ($unknown !== []) {
    fwrite(STDERR, '未知变体：' . implode(',', $unknown) . '（可选：' . implode(',', array_keys(EVAL_VARIANTS)) . '）' . "\n");
    exit(2);
}
// 回退判定要有参照：选了非基线变体时自动带上 base
if (!in_array('base', $variantNames, true) && $variantNames !== ['legacy']) {
    array_unshift($variantNames, 'base');
}

if (!is_file($goldPath)) {
    fwrite(STDERR, "金标集不存在：{$goldPath}\n");
    exit(2);
}
$gold = json_decode((string) file_get_contents($goldPath), true);
if (!is_array($gold) || empty($gold['cases'])) {
    fwrite(STDERR, "金标集无法解析或为空：{$goldPath}\n");
    exit(2);
}

$dbPath = RagSearch::resolveDbPath();
if (!is_file($dbPath)) {
    fwrite(STDERR, "索引不存在（{$dbPath}），先跑 php bin/hyperf.php rag:build\n");
    exit(2);
}
$rag = new RagSearch($dbPath);
$pdo = $rag->getPdo();

// ── 查询向量：provider 指纹入键，换模型后旧缓存自然不参与 ──
$client = RagSearch::semanticClientFromConfig();
if (!$offline && ($client === null || !$client->isConfigured())) {
    fwrite(STDERR, "embedding provider 未配置（ai.rag.enabled/providers），向量通道无法评测\n");
    exit(2);
}
$fingerprint = $client !== null ? $client->describe() : 'unconfigured';
$embCachePath = $root . '/runtime/rag_eval_emb.json';
$embCache = [];
if (is_file($embCachePath)) {
    $decoded = json_decode((string) file_get_contents($embCachePath), true);
    if (is_array($decoded)) {
        $embCache = $decoded;
    }
}
/** @var array<string, string> 本轮新增/命中的键，用于回写 */
$touched = [];

$maxPool = 20;
foreach ($variantNames as $name) {
    $maxPool = max($maxPool, $forcePool ?? EVAL_VARIANTS[$name]['pool']);
}
if ($wantRerank && !RagSearch::rerankEnabled()) {
    fwrite(STDERR, "ai.rag.rerank.enabled=false，忽略 --rerank（只评融合）\n");
    $wantRerank = false;
} elseif ($wantRerank) {
    // live 变体的池由精排器容量决定，两路召回必须至少召到那个宽度
    $maxPool = max($maxPool, RetrievalPipeline::poolSize($k, RagSearch::rerankMaxCandidates()));
}

$stats = [];
$misses = [];
$skipped = 0;

foreach ($gold['cases'] as $case) {
    $query = trim((string) ($case['query'] ?? ''));
    if ($query === '' || empty($case['expect'])) {
        continue;
    }
    $tier = (string) ($case['tier'] ?? 'signature');
    $expect = array_map('strval', (array) $case['expect']);

    $embKey = $fingerprint . '|' . $query;
    $vec = null;
    if (isset($embCache[$embKey]) && is_array($embCache[$embKey])) {
        $touched[$embKey] = true;
        $vec = (new VectorIndex($pdo))->topByCosine($embCache[$embKey], $maxPool, null);
    } elseif (!$offline && $client !== null) {
        for ($try = 0; $try < 3 && $vec === null; $try++) {
            try {
                $embedded = $client->embed([$query])[0] ?? null;
                if ($embedded === null) {
                    break;
                }
                $embCache[$embKey] = $embedded;
                $touched[$embKey] = true;
                $vec = (new VectorIndex($pdo))->topByCosine($embedded, $maxPool, null);
            } catch (Throwable $e) {
                usleep(400000);
            }
        }
    }
    if ($vec === null) {
        $skipped++;
        fwrite(STDERR, "  [skip] 无可用查询向量（--offline 缺缓存或 embedding 失败）：{$query}\n");
        continue;
    }

    $lexical = (new LexicalIndex($pdo))->search($query, LexicalIndex::splitTerms($query), $maxPool, null);

    foreach ($variantNames as $name) {
        $variant = EVAL_VARIANTS[$name];
        $pool = $forcePool ?? (int) $variant['pool'];
        if ($variant['merge'] === 'legacy') {
            $results = legacyMerge(
                array_slice($vec, 0, $pool),
                array_slice($lexical, 0, $pool),
                $k,
            );
        } else {
            $chunks = RetrievalPipeline::fuse(
                array_slice($lexical, 0, $pool),
                array_slice($vec, 0, $pool),
                $pool,
                ['rrfK' => (int) $variant['rrfK'], 'weights' => ['lexical' => 1.0, 'vector' => (float) $variant['vectorWeight']]],
            );
            if ((int) $variant['quota'] > 0) {
                $chunks = RetrievalPipeline::applySourceQuota($chunks, (int) $variant['quota']);
            }
            $results = array_slice(array_map(fn($c): array => $c->result, $chunks), 0, $k);
        }
        note($misses, $name, $tier, $query, score($stats, $name, $tier, $results, $expect, $vec), $expect);
    }

    if ($wantRerank) {
        $live = liveRetrieve($query, $lexical, $vec, $k);
        if ($live !== null) {
            note($misses, 'live', $tier, $query, score($stats, 'live', $tier, $live, $expect, $vec), $expect);
        }
    }
}

if ($touched !== []) {
    // 上限保护：保留全部本轮用到的键，其余按写入顺序留前 400 条
    $keep = [];
    foreach ($embCache as $key => $vector) {
        if (count($keep) >= 400 && !isset($touched[$key])) {
            continue;
        }
        $keep[$key] = $vector;
    }
    $encoded = json_encode($keep, JSON_UNESCAPED_SLASHES);
    if ($encoded !== false) {
        if (!is_dir($root . '/runtime')) {
            @mkdir($root . '/runtime', 0755, true);
        }
        @file_put_contents($embCachePath, $encoded);
    }
}

if ($skipped > 0) {
    fwrite(STDERR, "\n跳过 {$skipped} 条（无向量），以下指标基于其余用例\n");
}

printf("金标集 %s（%d 条），k=%d，池=%s\n", basename($goldPath), count($gold['cases']), $k,
    $forcePool !== null ? (string) $forcePool : '按变体');
foreach (['ALL', 'signature', 'paraphrase'] as $tier) {
    echo "\n─── {$tier} ───\n";
    printf("%-12s %5s %7s %7s %7s %7s %6s %7s\n", 'variant', 'n', 'hit@1', 'hit@3', 'hit@5', 'MRR', 'mono', 'alive');
    foreach (array_merge($variantNames, $wantRerank ? ['live'] : []) as $name) {
        $s = $stats[$name][$tier] ?? null;
        if ($s === null || $s['n'] === 0) {
            continue;
        }
        printf("%-12s %5d %6.1f%% %6.1f%% %6.1f%% %7.3f %6.1f %6.1f%%\n",
            $name, $s['n'],
            100 * $s['hit1'] / $s['n'], 100 * $s['hit3'] / $s['n'], 100 * $s['hit5'] / $s['n'],
            $s['mrr'] / $s['n'], $s['mono'] / $s['n'], 100 * $s['alive'] / $s['n']);
    }
}

$fail = 0;
foreach (array_merge($variantNames, $wantRerank ? ['live'] : []) as $name) {
    if ($name === 'base' || $name === 'legacy') {
        continue;
    }
    $base = $stats['base']['paraphrase'] ?? null;
    $cur = $stats[$name]['paraphrase'] ?? null;
    if ($base === null || $cur === null || $base['n'] === 0) {
        continue;
    }
    $baseRate = $base['hit5'] / $base['n'];
    $curRate = $cur['hit5'] / $cur['n'];
    if ($curRate < $baseRate - 1e-9) {
        printf("\n[FAIL] %s 的 paraphrase hit@5=%.1f%% 低于 base=%.1f%%：纯语义召回回退\n",
            $name, 100 * $curRate, 100 * $baseRate);
        $fail = 1;
    }
}

if ($misses !== []) {
    echo "\n─── top5 未命中明细 ───\n";
    foreach ($misses as $line) {
        echo $line . "\n";
    }
}

exit($fail);

/* ─── 计算函数 ──────────────────────────────────────────── */

/**
 * rerank 关闭时线上走的合并策略：向量在前、词法补足、按键去重、截断到 k。
 *
 * @param array<int, array> $vector
 * @param array<int, array> $lexical
 * @return array<int, array>
 */
function legacyMerge(array $vector, array $lexical, int $k): array
{
    $seen = [];
    $out = [];
    foreach (array_merge($vector, $lexical) as $result) {
        $key = $result['source'] . '#' . $result['title'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $result;
        if (count($out) >= $k) {
            break;
        }
    }
    return $out;
}

/**
 * 用线上配置的精排器跑完整 retrieve()（融合 + 精排），失败返回 null 不影响其余变体。
 *
 * @param array<int, array> $lexical
 * @param array<int, array> $vector
 * @return array<int, array>|null
 */
function liveRetrieve(string $query, array $lexical, array $vector, int $k): ?array
{
    try {
        $method = new ReflectionMethod(RagSearch::class, 'rerankerFromConfig');
        $reranker = $method->invoke(null);
        $r = (new RetrievalPipeline($reranker))->retrieve($query, $lexical, $vector, $k);
        return $r['results'];
    } catch (Throwable $e) {
        fwrite(STDERR, '  [skip] live 变体失败：' . $e->getMessage() . "\n");
        return null;
    }
}

/**
 * 累计单条用例在各档位上的指标，返回首个命中排名（0 表示 top5 未命中）。
 *
 * @param array<string, array<string, array<string, float>>> $stats
 * @param array<int, array> $results
 * @param array<int, string> $expect
 * @param array<int, array> $vector
 */
function score(array &$stats, string $variant, string $tier, array $results, array $expect, array $vector): int
{
    $sources = array_column(array_slice($results, 0, 5), 'source');
    $firstRank = 0;
    foreach ($sources as $i => $source) {
        if (in_array($source, $expect, true)) {
            $firstRank = $i + 1;
            break;
        }
    }

    $alive = 0;
    if ($vector !== []) {
        $top = $vector[0];
        foreach ($results as $r) {
            if ($r['source'] === $top['source'] && $r['title'] === $top['title']) {
                $alive = 1;
                break;
            }
        }
    }

    $counts = array_count_values($sources);
    $mono = $counts === [] ? 0 : max($counts);

    foreach (['ALL', $tier] as $bucket) {
        $s = $stats[$variant][$bucket] ?? ['n' => 0, 'hit1' => 0, 'hit3' => 0, 'hit5' => 0, 'mrr' => 0.0, 'mono' => 0.0, 'alive' => 0];
        $s['n']++;
        $s['hit1'] += $firstRank === 1 ? 1 : 0;
        $s['hit3'] += $firstRank > 0 && $firstRank <= 3 ? 1 : 0;
        $s['hit5'] += $firstRank > 0 ? 1 : 0;
        $s['mrr'] += $firstRank > 0 ? 1 / $firstRank : 0;
        $s['mono'] += $mono;
        $s['alive'] += $alive;
        $stats[$variant][$bucket] = $s;
    }

    return $firstRank;
}

/**
 * top5 未命中时记一条明细，供人工判断是金标集期望写窄了还是召回真缺料。
 *
 * @param array<string, string> $misses
 * @param array<int, string> $expect
 */
function note(array &$misses, string $variant, string $tier, string $query, int $firstRank, array $expect): void
{
    if ($firstRank > 0 || count($misses) >= 60) {
        return;
    }
    $misses[$variant . '|' . $query] = sprintf('[%s][%s] %s（期望 %s）',
        $variant, $tier, $query, implode(' / ', $expect));
}
