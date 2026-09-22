<?php

use App\Rag\QueryPreProcessor;

/* ─── classify 规则表 ────────────────────────────────────── */

test('classify maps query features to topic weights', function () {
    expect(QueryPreProcessor::classify('MixinApplyError thrown'))->toBe(['patterns' => 1.3, '日志分析' => 1.2]);
    expect(QueryPreProcessor::classify('服务器一直闪退'))->toBe(['patterns' => 1.2, '日志分析' => 1.2]);
    expect(QueryPreProcessor::classify('FCL 渲染器选哪个'))
        ->toBe(['mobile_launcher' => 1.5, 'android-native-lib' => 1.2]);
    expect(QueryPreProcessor::classify('NeoForge mod load'))
        ->toBe(['日志分析' => 1.3]);
    expect(QueryPreProcessor::classify('latest.log 怎么看'))
        ->toBe(['format' => 1.4]);
    expect(QueryPreProcessor::classify('zzz nothing relevant'))->toBe([]);
});

test('classify takes max weight when multiple rules hit', function () {
    // OOM（patterns×1.3）+ 网络（日志分析×1.1）+ 日志分析（×1.3 规则也命中 Fabric 族? no）
    $w = QueryPreProcessor::classify('OutOfMemoryError 连不上服务器 Fabric');
    expect($w['patterns'])->toBe(1.3);
    expect($w['日志分析'])->toBe(1.3, 'Fabric 规则 1.3 覆盖网络规则 1.1');
});

/* ─── rewrite 清洗 ───────────────────────────────────────── */

test('sanitizeRewrite strips bullets quotes and caps length', function () {
    expect(QueryPreProcessor::sanitizeRewrite(" OutOfMemoryError, Java heap space "))->toBe('OutOfMemoryError, Java heap space');
    expect(QueryPreProcessor::sanitizeRewrite('- SIGSEGV, crash'))
        ->toBe('SIGSEGV, crash');
    expect(QueryPreProcessor::sanitizeRewrite('"TimeoutException"'))->toBe('TimeoutException');
    expect(QueryPreProcessor::sanitizeRewrite('  '))->toBeNull();
    $long = QueryPreProcessor::sanitizeRewrite(str_repeat('词', 300));
    expect(mb_strlen((string) $long))->toBe(160, 'capped at REWRITE_MAX_CHARS');
});

test('rewrite fails open without AI keys', function () {
    $cfgRef = new ReflectionClass(\App\Config::class);
    $dataProp = $cfgRef->getProperty('data');
    $orig = $dataProp->getValue();
    $data = $orig;
    $data['ai']['apiKeys'] = [];
    unset($data['ai']['apiKey']);
    $dataProp->setValue(null, $data);

    expect(QueryPreProcessor::rewrite('内存不足 闪退'))->toBeNull();

    $dataProp->setValue(null, $orig);
});

/* ─── applyTopicBias ─────────────────────────────────────── */

test('topic bias promotes matched directories stably without touching order of others', function () {
    $results = [
        ['source' => 'misc/x.md', 'title' => 'X1'],
        ['source' => 'patterns/p.md', 'title' => 'P1'],
        ['source' => 'misc/y.md', 'title' => 'X2'],
        ['source' => 'patterns/q.md', 'title' => 'P2'],
    ];
    $biased = QueryPreProcessor::applyTopicBias($results, ['patterns' => 1.3]);

    // patterns 命中项整体前移，组内保持原相对次序（稳定）
    expect(array_column($biased, 'title'))->toBe(['P1', 'P2', 'X1', 'X2']);

    // 空权重/空结果直通
    expect(QueryPreProcessor::applyTopicBias($results, []))->toBe($results);
    expect(QueryPreProcessor::applyTopicBias([], ['a' => 2.0]))->toBe([]);
});

/* ─── preprocess 组合与开关 ──────────────────────────────── */

test('preprocess returns weights but keeps query when switch off', function () {
    $cfgRef = new ReflectionClass(\App\Config::class);
    $dataProp = $cfgRef->getProperty('data');
    $orig = $dataProp->getValue();
    $data = $orig;
    $data['ai']['rag']['queryRewrite']['enabled'] = false;
    $dataProp->setValue(null, $data);

    $pre = QueryPreProcessor::preprocess('OOM 崩溃');
    expect($pre['query'])->toBe('OOM 崩溃');
    expect($pre['rewritten'])->toBeFalse();
    expect($pre['weights']['patterns'])->toBe(1.3);

    $dataProp->setValue(null, $orig);
});

test('preprocess degrades gracefully when rewrite fails with switch on', function () {
    $cfgRef = new ReflectionClass(\App\Config::class);
    $dataProp = $cfgRef->getProperty('data');
    $orig = $dataProp->getValue();
    $data = $orig;
    $data['ai']['rag']['queryRewrite']['enabled'] = true;
    $data['ai']['apiKeys'] = [];
    unset($data['ai']['apiKey']);
    $dataProp->setValue(null, $data);

    $pre = QueryPreProcessor::preprocess('Mixin 注入失败');
    expect($pre['query'])->toBe('Mixin 注入失败', 'rewrite 失败保持原查询');
    expect($pre['rewritten'])->toBeFalse();
    expect($pre['weights'])->not->toBe([], '分类偏置不依赖 LLM，开关开启即生效');

    $dataProp->setValue(null, $orig);
});

/* ─── RagSearch 集成：默认关闭零影响、开启后偏置生效 ─────── */

function qppRag(): \App\Rag\RagSearch
{
    $dbPath = CORE_PATH . '/tmp/rag_qpp_' . uniqid() . '.db';
    $rag = new \App\Rag\RagSearch($dbPath);
    $rag->getPdo()->exec("INSERT INTO docs(title, body, source) VALUES
        ('内容甲', 'alpha content 匹配词', 'misc/a.md'),
        ('内容乙', 'alpha content 匹配词', 'patterns/b.md'),
        ('内容丙', 'alpha content 匹配词', 'misc/c.md')");
    $GLOBALS['qppDb'] = $dbPath;
    return $rag;
}
afterEach(function () {
    if (!empty($GLOBALS['qppDb']) && file_exists($GLOBALS['qppDb'])) {
        unlink($GLOBALS['qppDb']);
    }
    unset($GLOBALS['qppDb']);
});

test('search with queryRewrite off is untouched even for classifiable queries', function () {
    $rag = qppRag();
    $order = array_column($rag->search('alpha 崩溃', 3), 'title');
    expect($order)->toBe(['内容甲', '内容乙', '内容丙'], '无偏置时维持 LIKE 原次序');
});

test('search with queryRewrite on biases untopic’d results toward classified dir', function () {
    // 关闭语义避免缓存/网络干扰，仅验证词法 + 偏置接线
    $cfgRef = new ReflectionClass(\App\Config::class);
    $dataProp = $cfgRef->getProperty('data');
    $orig = $dataProp->getValue();
    $data = $orig;
    $data['ai']['rag']['enabled'] = false;
    $data['ai']['rag']['queryRewrite']['enabled'] = true;
    $data['ai']['apiKeys'] = [];
    $dataProp->setValue(null, $data);

    $rag = qppRag();
    $order = array_column($rag->search('alpha 崩溃', 3), 'title');
    expect($order[0])->toBe('内容乙', 'patterns 目录提权到首位');

    // 显式 topic 时偏置让位于圈定目录
    $scoped = array_column($rag->search('alpha 崩溃', 3, 'misc'), 'title');
    expect($scoped)->not->toBe([]);
    foreach ($scoped as $t) {
        expect($t)->not->toBe('内容乙');
    }

    $dataProp->setValue(null, $orig);
});
