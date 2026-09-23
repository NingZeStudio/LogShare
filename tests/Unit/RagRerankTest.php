<?php

use App\Rag\RagSearch;
use App\Rag\Rerank\HttpReranker;

/* ─── 响应形态解析 ──────────────────────────────────────── */

test('parseOrder sorts by relevance_score descending, not response order', function () {
    $order = HttpReranker::parseOrder([
        'results' => [
            ['index' => 1, 'relevance_score' => 0.31],
            ['index' => 0, 'relevance_score' => 0.88],
            ['index' => 2, 'relevance_score' => 0.52],
        ],
    ], 3);

    expect($order)->toBe([0, 2, 1]);
});

test('parseOrder accepts bare array with score field', function () {
    $order = HttpReranker::parseOrder([
        ['index' => 2, 'score' => 0.9],
        ['index' => 0, 'score' => 0.1],
    ], 3);

    expect($order)->toBe([2, 0]);
});

test('parseOrder trusts response order when scores are absent', function () {
    $order = HttpReranker::parseOrder([
        'results' => [['index' => 2], ['index' => 1], ['index' => 0]],
    ], 3);

    expect($order)->toBe([2, 1, 0]);
});

test('parseOrder drops out-of-range and duplicate indices', function () {
    $order = HttpReranker::parseOrder([
        'results' => [
            ['index' => 7, 'relevance_score' => 1.0],
            ['index' => 1, 'relevance_score' => 0.6],
            ['index' => 1, 'relevance_score' => 0.9],
            ['index' => 0, 'relevance_score' => 0.2],
        ],
    ], 2);

    expect($order)->toBe([1, 0]);
});

test('parseOrder returns null when fewer than two usable indices remain', function () {
    expect(HttpReranker::parseOrder(['results' => [['index' => 0, 'relevance_score' => 0.5]]], 3))->toBeNull();
    expect(HttpReranker::parseOrder(['results' => []], 3))->toBeNull();
    expect(HttpReranker::parseOrder(['error' => 'bad request'], 3))->toBeNull();
});

/* ─── 端点地址校验 ──────────────────────────────────────── */

test('normalizeEndpoint rejects non-HTTP schemes and empty hosts', function () {
    expect(fn() => HttpReranker::normalizeEndpoint('ftp://rerank.internal'))->toThrow(InvalidArgumentException::class);
    expect(fn() => HttpReranker::normalizeEndpoint('not a url'))->toThrow(InvalidArgumentException::class);
});

test('normalizeEndpoint rejects private ranges regardless of the loopback switch', function () {
    expect(fn() => HttpReranker::normalizeEndpoint('http://10.0.0.8:8080', true))->toThrow(InvalidArgumentException::class);
    expect(fn() => HttpReranker::normalizeEndpoint('http://192.168.1.20:8080'))->toThrow(InvalidArgumentException::class);
});

test('normalizeEndpoint gates loopback behind an explicit switch', function () {
    expect(fn() => HttpReranker::normalizeEndpoint('http://127.0.0.1:8080'))->toThrow(InvalidArgumentException::class);
    expect(HttpReranker::normalizeEndpoint('http://127.0.0.1:8080/', true))->toBe('http://127.0.0.1:8080');
});

test('normalizeEndpoint accepts public https and strips trailing slash', function () {
    expect(HttpReranker::normalizeEndpoint('https://api.siliconflow.cn/v1'))->toBe('https://api.siliconflow.cn/v1');
});

/* ─── 直通与降级 ────────────────────────────────────────── */

test('rerank passes through when fewer than two candidates', function () {
    $reranker = new HttpReranker('https://example.invalid', '', 'bge-reranker');
    $one = [['title' => 'a', 'body' => 'b', 'source' => 's.md', 'score' => 1, 'snippet' => '']];

    expect($reranker->rerank('q', $one))->toBe($one);
    expect($reranker->rerank('q', []))->toBe([]);
});

test('isActive is true so the pipeline marks results as reranked', function () {
    expect((new HttpReranker('https://example.invalid', '', 'm'))->isActive())->toBeTrue();
});

/* ─── 命中目录归属 ──────────────────────────────────────── */

test('topicOfSource mirrors the grouping rule used by topics()', function () {
    expect(RagSearch::topicOfSource('patterns/mixin.md'))->toBe('patterns');
    expect(RagSearch::topicOfSource('/patterns/mixin.md'))->toBe('patterns');
    expect(RagSearch::topicOfSource('oom.md'))->toBe('(根目录)');
});
