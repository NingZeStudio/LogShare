<?php

declare(strict_types=1);

use App\Client\RedisClient;
use App\Rag\RagSearch;
use App\Rag\RetrievalMetrics;
use Tests\Mocks\RedisMock;

function rmSetConfig(array $patch): void
{
    $dataProp = (new ReflectionClass(\App\Config::class))->getProperty('data');
    if (!isset($GLOBALS['rmOrigConfig'])) {
        $GLOBALS['rmOrigConfig'] = [$dataProp, $dataProp->getValue()];
    }
    $data = $dataProp->getValue() ?? [];
    foreach ($patch as $path => $value) {
        $ref = &$data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($ref[$key] ?? null)) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
        $ref = $value;
        unset($ref);
    }
    $dataProp->setValue(null, $data);
}

beforeEach(function () {
    RedisMock::reset();
    RedisClient::setTestConnection(new RedisMock());
});

afterEach(function () {
    RedisClient::setTestConnection(null);
    if (!empty($GLOBALS['rmOrigConfig'])) {
        [$dataProp, $orig] = $GLOBALS['rmOrigConfig'];
        $dataProp->setValue(null, $orig);
        unset($GLOBALS['rmOrigConfig']);
    }
    if (!empty($GLOBALS['rmDb']) && file_exists($GLOBALS['rmDb'])) {
        unlink($GLOBALS['rmDb']);
    }
    unset($GLOBALS['rmDb']);
});

test('telemetry config defaults to enabled with 500ms slow threshold', function () {
    rmSetConfig(['ai.rag' => []]);
    expect(RetrievalMetrics::config())->toBe(['enabled' => true, 'slowMs' => 500]);

    rmSetConfig(['ai.rag.telemetry' => ['enabled' => false, 'slowMs' => 42]]);
    expect(RetrievalMetrics::config())->toBe(['enabled' => false, 'slowMs' => 42]);
});

test('record aggregates per-stage timings and hits in the daily hash', function () {
    rmSetConfig(['ai.rag' => []]);
    RetrievalMetrics::record([
        'query' => '崩溃', 'k' => 5, 'topic' => null,
        'total_ms' => 12.5, 'lexical_ms' => 3.0, 'lexical_hits' => 8,
        'semantic_ms' => 9.0, 'vector_hits' => 4,
        'rewritten' => true, 'fused' => 10, 'final' => 5,
    ]);
    RetrievalMetrics::record([
        'query' => '空手', 'k' => 5, 'topic' => 'patterns',
        'total_ms' => 1.0, 'lexical_ms' => 1.0, 'lexical_hits' => 0, 'final' => 0,
    ]);

    $s = RetrievalMetrics::summary();
    expect($s)->not->toBeNull();
    expect((int) $s['queries'])->toBe(2);
    expect((int) $s['zero_results'])->toBe(1, '空结果计 1 次');
    expect((float) $s['total_ms:sum'])->toBe(13.5);
    expect((float) $s['total_ms:max'])->toBe(12.5);
    expect((int) $s['rewritten'])->toBe(1);
    expect((int) $s['fused'])->toBe(10);
    expect((int) $s['vector_searches'])->toBe(1);
    expect((int) $s['vector_hits_sum'])->toBe(4);
});

test('slow queries are flagged, listed and logged', function () {
    rmSetConfig(['ai.rag.telemetry.slowMs' => 5]);
    RetrievalMetrics::record([
        'query' => '慢查询测试', 'k' => 5, 'topic' => null,
        'total_ms' => 800.0, 'lexical_ms' => 10.0, 'semantic_ms' => 790.0,
        'vector_hits' => 20, 'reranked' => true, 'final' => 5,
    ]);
    RetrievalMetrics::record([
        'query' => '快查询', 'k' => 5, 'topic' => null, 'total_ms' => 1.0, 'final' => 3,
    ]);

    $s = RetrievalMetrics::summary();
    expect((int) $s['slow'])->toBe(1);
    $slow = RetrievalMetrics::recentSlow();
    expect($slow)->toHaveCount(1);
    expect($slow[0]['query'])->toBe('慢查询测试');
    expect((float) $slow[0]['total_ms'])->toBe(800.0);
    expect($slow[0]['reranked'])->toBeTrue();
});

test('counter increments standalone fields', function () {
    rmSetConfig(['ai.rag' => []]);
    RetrievalMetrics::counter('embed_cache_hits');
    RetrievalMetrics::counter('embed_api_calls', 3);
    expect((int) RetrievalMetrics::summary()['embed_cache_hits'])->toBe(1);
    expect((int) RetrievalMetrics::summary()['embed_api_calls'])->toBe(3);
});

test('disabled telemetry writes nothing and redis outage is fail-open', function () {
    rmSetConfig(['ai.rag.telemetry.enabled' => false]);
    RetrievalMetrics::record(['query' => 'q', 'total_ms' => 9999.0]);
    expect(RetrievalMetrics::summary())->toBeNull();

    rmSetConfig(['ai.rag.telemetry.enabled' => true]);
    RedisMock::$failAll = true;
    RetrievalMetrics::record(['query' => 'q', 'total_ms' => 9999.0]); // 不抛异常即通过
    RetrievalMetrics::counter('x');
    expect(RetrievalMetrics::summary())->toBeNull();
    expect(RetrievalMetrics::recentSlow())->toBe([]);
});

test('search records stage metrics end to end', function () {
    rmSetConfig(['ai.rag' => ['enabled' => false]]); // 纯词法路径，不触网
    $dbPath = CORE_PATH . '/tmp/rag_rm_' . uniqid() . '.db';
    $GLOBALS['rmDb'] = $dbPath;
    $rag = new RagSearch($dbPath);
    $rag->getPdo()->prepare('INSERT INTO docs(title, body, source) VALUES (?, ?, ?)')
        ->execute(['OOM 排查', 'java.lang.OutOfMemoryError 堆内存不足', 'oom.md']);

    $results = $rag->search('OutOfMemoryError', 5);
    expect($results)->not->toBe([]);

    $s = RetrievalMetrics::summary();
    expect($s)->not->toBeNull();
    expect((int) $s['queries'])->toBe(1);
    expect((float) $s['lexical_ms:sum'])->toBeGreaterThan(0.0);
    expect((int) $s['zero_results'])->toBe(0);

    // 空结果查询也计入且标记 zero
    $rag->search('不存在的词条xyz', 5);
    $s = RetrievalMetrics::summary();
    expect((int) $s['queries'])->toBe(2);
    expect((int) $s['zero_results'])->toBe(1);
});
