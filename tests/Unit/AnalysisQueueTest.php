<?php

use App\Ai\AnalysisQueue;
use App\Client\RedisStreams;
use App\Sse\SseEmitter;
use App\Sse\StreamEmitter;

function queueConfig(array $queue, bool $agent = false): void
{
    $ref = new ReflectionClass(\App\Config::class);
    $prop = $ref->getProperty('data');
    $data = $prop->getValue();
    $data['ai']['queue'] = $queue;
    $data['ai']['agent']['enabled'] = $agent;
    $prop->setValue(null, $data);
}

function queueOrigConfig(): array
{
    $ref = new ReflectionClass(\App\Config::class);
    return [$ref->getProperty('data'), $ref->getProperty('data')->getValue()];
}

beforeEach(function () {
    // 本文件以 RedisMock 为地基（reset/failAll/peekStream/groups 反射均为 mock 语义）；
    // ext-redis 存在时 RedisClient 走真实连接，mock 被整体旁路、跨用例状态互串。
    // 真 Redis 环境的同等语义由 tests/Integration/AiQueueRedisTest.php 覆盖。
    if (extension_loaded('redis')) {
        $this->markTestSkipped('本文件依赖 RedisMock；ext-redis 环境由 AiQueueRedisTest 覆盖');
    }
    \Tests\Mocks\RedisMock::reset();
    $this->orig = queueOrigConfig();
});

afterEach(function () {
    if (($this->orig ?? null) === null) {
        return;
    }
    [$prop, $orig] = $this->orig;
    $prop->setValue(null, $orig);
    \Tests\Mocks\RedisMock::reset();
});

test('queue config defaults and merge', function () {
    queueConfig([]);
    $cfg = AnalysisQueue::config();
    expect($cfg)->toMatchArray([
        'enabled' => false,
        'maxConcurrent' => 2,
        'maxQueue' => 50,
        'waitTimeout' => 300,
        'failOpen' => true,
    ]);

    queueConfig(['enabled' => true, 'maxQueue' => 5]);
    $cfg = AnalysisQueue::config();
    expect($cfg['enabled'])->toBeTrue();
    expect($cfg['maxQueue'])->toBe(5);
    expect($cfg['maxConcurrent'])->toBe(2);
});

test('cacheKeyFor matches inline paths', function () {
    queueConfig([], agent: false);
    expect(AnalysisQueue::cacheKeyFor('ai:analysis:abc123'))->toBe('analysis-v2:ai:analysis:abc123');
    queueConfig([], agent: true);
    expect(AnalysisQueue::cacheKeyFor('ai:analysis:abc123'))->toBe('ai:analysis:abc123');
});

test('enqueue stores payload and queue entry', function () {
    queueConfig(['enabled' => true]);
    $job = AnalysisQueue::enqueue('log body', 'ai:analysis:abc', null, 1800);

    expect($job['attached'])->toBeFalse();
    expect(strlen($job['jobId']))->toBe(16);
    expect(AnalysisQueue::queueDepth())->toBe(1);

    $payload = json_decode((string) gzuncompress((string) RedisStreams::get(AnalysisQueue::payloadKey($job['jobId']))), true);
    expect($payload)->toMatchArray(['content' => 'log body', 'cacheKey' => 'ai:analysis:abc', 'cacheTTL' => 1800]);
});

test('enqueue dedups active job by cacheKey', function () {
    queueConfig(['enabled' => true]);
    $first = AnalysisQueue::enqueue('body a', 'ai:analysis:dup', null, 1800);
    $second = AnalysisQueue::enqueue('body b', 'ai:analysis:dup', null, 1800);

    expect($second['attached'])->toBeTrue();
    expect($second['jobId'])->toBe($first['jobId']);
    expect(AnalysisQueue::queueDepth())->toBe(1);

    // 不同 cacheKey 不互串
    $other = AnalysisQueue::enqueue('body c', 'ai:analysis:other', null, 1800);
    expect($other['jobId'])->not->toBe($first['jobId']);
    expect(AnalysisQueue::queueDepth())->toBe(2);
});

test('relay forwards frames until done', function () {
    queueConfig(['enabled' => true, 'waitTimeout' => 5]);
    $events = StreamEmitter::eventsKey('job1');
    RedisStreams::xAdd($events, ['event' => '', 'data' => '{"choices":[{"delta":{"content":"hi"}}]}']);
    RedisStreams::xAdd($events, ['event' => 'done', 'data' => '{"status":"completed"}']);
    RedisStreams::xAdd($events, ['event' => 'status', 'data' => '{"type":"thinking","delta":"after-done"}']);

    ob_start();
    AnalysisQueue::relay('job1', null);
    $out = ob_get_clean();

    expect($out)->toContain('event: status' . "\n" . 'data: {"type":"queued"');
    expect($out)->toContain("data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\n");
    expect($out)->toContain("event: done\ndata: {\"status\":\"completed\"}\n\n");
    // done 之后的帧不得转发
    expect($out)->not->toContain('after-done');
});

test('relay emits timeout error frame when no terminal event arrives', function () {
    queueConfig(['enabled' => true, 'waitTimeout' => 1]);

    ob_start();
    AnalysisQueue::relay('missing-job', null);
    $out = ob_get_clean();

    expect($out)->toContain('event: error');
    expect($out)->toContain('排队等待超时');
});

test('consumeJob executes legacy path and cleans up', function () {
    // agent 关闭 → AIClient 路径；无 AI Key 时 analyzeStream 收敛为流内 error 帧
    queueConfig(['enabled' => true], agent: false);
    $job = AnalysisQueue::enqueue('some log', 'ai:analysis:cj1', null, 1800);

    RedisStreams::xGroupCreate(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP);
    $entries = RedisStreams::xReadGroup(AnalysisQueue::GROUP, 'w1', AnalysisQueue::QUEUE_KEY, 0);
    expect($entries)->toHaveCount(1);

    AnalysisQueue::consumeJob($entries[0][0], $job['jobId']);

    // payload/active 清理、条目已 ACK、事件流以 error 收尾（Key 未配置）
    expect(RedisStreams::get(AnalysisQueue::payloadKey($job['jobId'])))->toBeNull();
    expect(RedisStreams::get('ai:job:active:' . hash('sha256', 'ai:analysis:cj1')))->toBeNull();
    $pending = (new ReflectionClass(\Tests\Mocks\RedisMock::class))->getProperty('groups');
    $groups = $pending->getValue();
    expect($groups[AnalysisQueue::QUEUE_KEY . '|' . AnalysisQueue::GROUP]['pending'])->toBe([]);

    $frames = \Tests\Mocks\RedisMock::peekStream(StreamEmitter::eventsKey($job['jobId']));
    expect($frames)->not->toBe([]);
    expect($frames[count($frames) - 1][1]['event'])->toBe('error');
});

test('consumeJob skips job whose running lock is held', function () {
    queueConfig(['enabled' => true]);
    $job = AnalysisQueue::enqueue('some log', 'ai:analysis:cj2', null, 1800);
    RedisStreams::setNxEx('ai:job:' . $job['jobId'] . ':running', 'other-consumer', 60);

    RedisStreams::xGroupCreate(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP);
    $entries = RedisStreams::xReadGroup(AnalysisQueue::GROUP, 'w1', AnalysisQueue::QUEUE_KEY, 0);
    AnalysisQueue::consumeJob($entries[0][0], $job['jobId']);

    // 未执行、未 ACK：payload 仍在，条目保持 pending（等待下一轮 XAUTOCLAIM）
    expect(RedisStreams::get(AnalysisQueue::payloadKey($job['jobId'])))->not->toBeNull();
    $groups = (new ReflectionClass(\Tests\Mocks\RedisMock::class))->getProperty('groups')->getValue();
    expect($groups[AnalysisQueue::QUEUE_KEY . '|' . AnalysisQueue::GROUP]['pending'])->not->toBe([]);
});

test('consumeJob drops entry with missing payload', function () {
    queueConfig(['enabled' => true]);
    RedisStreams::xGroupCreate(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP);
    RedisStreams::xAdd(AnalysisQueue::QUEUE_KEY, ['jobId' => 'ghost-job']);
    $entries = RedisStreams::xReadGroup(AnalysisQueue::GROUP, 'w1', AnalysisQueue::QUEUE_KEY, 0);

    AnalysisQueue::consumeJob($entries[0][0], 'ghost-job');

    $groups = (new ReflectionClass(\Tests\Mocks\RedisMock::class))->getProperty('groups')->getValue();
    expect($groups[AnalysisQueue::QUEUE_KEY . '|' . AnalysisQueue::GROUP]['pending'])->toBe([]);
});

test('SseEmitter and StreamEmitter produce byte-identical frames', function () {
    queueConfig(['enabled' => true]);
    $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    $sse = new SseEmitter(null);
    ob_start();
    $sse->begin();
    $sse->emit('', json_encode(['choices' => [['delta' => ['content' => '你好']]]], $flags));
    $sse->emit('status', json_encode(['type' => 'tool', 'name' => 'rag_search'], $flags));
    $sse->finish('done', '{"status":"completed"}');
    $sseOut = ob_get_clean();

    $stream = new StreamEmitter('parity-job', 60);
    $stream->begin();
    $stream->emit('', json_encode(['choices' => [['delta' => ['content' => '你好']]]], $flags));
    $stream->emit('status', json_encode(['type' => 'tool', 'name' => 'rag_search'], $flags));
    $stream->finish('done', '{"status":"completed"}');

    $rebuilt = '';
    foreach (\Tests\Mocks\RedisMock::peekStream(StreamEmitter::eventsKey('parity-job')) as [, $fields]) {
        $event = (string) $fields['event'];
        $rebuilt .= ($event === '' ? 'data: ' : "event: {$event}\ndata: ") . $fields['data'] . "\n\n";
    }

    expect($rebuilt)->toBe($sseOut);
});

test('enqueue throws when redis fails so callers can fail open', function () {
    queueConfig(['enabled' => true]);
    \Tests\Mocks\RedisMock::$failAll = true;

    expect(fn() => AnalysisQueue::enqueue('x', 'ai:analysis:fail', null, 1800))
        ->toThrow(RuntimeException::class);
});

/** 具体子类供反射实例化（newInstanceWithoutConstructor 跳过 DI 代理构造器） */
class QueueTestController extends \App\Controller\AbstractController
{
}

function queueTestController(): QueueTestController
{
    return (new ReflectionClass(QueueTestController::class))->newInstanceWithoutConstructor();
}

test('queue full returns 429 with Retry-After before SSE begins', function () {
    queueConfig(['enabled' => true, 'maxQueue' => 2]);
    RedisStreams::xAdd(AnalysisQueue::QUEUE_KEY, ['jobId' => 'a']);
    RedisStreams::xAdd(AnalysisQueue::QUEUE_KEY, ['jobId' => 'b']);

    // 绕过 DI 代理构造函数：429 路径不触碰注入的 request/response
    $m = (new ReflectionClass(QueueTestController::class))->getMethod('runAiAnalysis');
    $resp = $m->invoke(queueTestController(), 'log body', 'ai:analysis:full429');
    expect($resp->getStatusCode())->toBe(429);
    expect($resp->getHeaderLine('Retry-After'))->toBe('30');
    // 未入队：深度不变
    expect(AnalysisQueue::queueDepth())->toBe(2);
});

test('queue unavailable without failOpen raises 503 ApiError', function () {
    queueConfig(['enabled' => true, 'failOpen' => false]);
    \Tests\Mocks\RedisMock::$failAll = true;

    $m = (new ReflectionClass(QueueTestController::class))->getMethod('runAiAnalysis');

    expect(fn() => $m->invoke(queueTestController(), 'log body', 'ai:analysis:nofailopen'))
        ->toThrow(\App\ApiError::class);
});

test('cache hit skips the queue entirely', function () {
    queueConfig(['enabled' => true]);
    // 预置缓存（agent 关闭 → legacy 键带 analysis-v2: 前缀）
    \App\Cache\RedisCache::Set('analysis-v2:ai:analysis:cached', 'cached answer', 60);

    $method = (new ReflectionClass(QueueTestController::class))->getMethod('aiCacheHit');

    expect($method->invoke(queueTestController(), 'ai:analysis:cached'))->toBeTrue();
    expect(AnalysisQueue::queueDepth())->toBe(0);
});

test('jobLifetime covers queue wait when timeout set, equals jobTtl when no timeout', function () {
    queueConfig(['enabled' => true, 'jobTtl' => 600, 'waitTimeout' => 300]);
    expect(AnalysisQueue::jobLifetime())->toBe(900);

    // waitTimeout <= 0 = 无排队超时：jobTtl 即任务总寿命
    queueConfig(['enabled' => true, 'jobTtl' => 7200, 'waitTimeout' => 0]);
    expect(AnalysisQueue::jobLifetime())->toBe(7200);
    queueConfig(['enabled' => true, 'jobTtl' => 7200, 'waitTimeout' => -1]);
    expect(AnalysisQueue::jobLifetime())->toBe(7200);
});

test('relay with no waitTimeout forwards frames without emitting timeout', function () {
    queueConfig(['enabled' => true, 'waitTimeout' => 0]);
    $events = StreamEmitter::eventsKey('no-timeout-job');
    RedisStreams::xAdd($events, ['event' => '', 'data' => '{"choices":[{"delta":{"content":"x"}}]}']);
    RedisStreams::xAdd($events, ['event' => 'done', 'data' => '{"status":"completed"}']);

    ob_start();
    AnalysisQueue::relay('no-timeout-job', null);
    $out = ob_get_clean();

    expect($out)->toContain("event: done\ndata: {\"status\":\"completed\"}\n\n");
    expect($out)->not->toContain('排队等待超时');
});

test('queueDepth counts undelivered and in-flight, not cumulative XLEN', function () {
    queueConfig(['enabled' => true]);

    // 无消费组：保守回退 XLEN
    AnalysisQueue::enqueue('a', 'ai:analysis:dq1', null, 1800);
    expect(AnalysisQueue::queueDepth())->toBe(1);

    RedisStreams::xGroupCreate(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP);
    expect(AnalysisQueue::queueDepth())->toBe(1); // lag=1

    // 已投递未确认：仍计入深度（pending=1，lag=0）
    $first = RedisStreams::xReadGroup(AnalysisQueue::GROUP, 'w1', AnalysisQueue::QUEUE_KEY, 0, 2);
    expect(AnalysisQueue::queueDepth())->toBe(1);

    // 再入队一个：lag=1 + pending=1 = 2
    AnalysisQueue::enqueue('b', 'ai:analysis:dq2', null, 1800);
    expect(AnalysisQueue::queueDepth())->toBe(2);

    // ACK 第一个：深度只降一个
    RedisStreams::xAck(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP, $first[0][0]);
    expect(AnalysisQueue::queueDepth())->toBe(1);

    // 第二个读取并 ACK：Stream 累计条目 XLEN 仍为 2，但真实深度必须归零
    // （2026-09-08 线上误 429 事故的回归点）
    $second = RedisStreams::xReadGroup(AnalysisQueue::GROUP, 'w1', AnalysisQueue::QUEUE_KEY, 0, 2);
    RedisStreams::xAck(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP, $second[0][0]);
    expect(RedisStreams::xLen(AnalysisQueue::QUEUE_KEY))->toBe(2);
    expect(AnalysisQueue::queueDepth())->toBe(0);
});

test('consumeJob sends terminal error frame when payload expired', function () {
    queueConfig(['enabled' => true]);
    RedisStreams::xGroupCreate(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP);
    RedisStreams::xAdd(AnalysisQueue::QUEUE_KEY, ['jobId' => 'ghost-exp']);
    $entries = RedisStreams::xReadGroup(AnalysisQueue::GROUP, 'w1', AnalysisQueue::QUEUE_KEY, 0);

    AnalysisQueue::consumeJob($entries[0][0], 'ghost-exp');

    // payload 缺失不再静默丢弃：补 error 终态帧，防止 waitTimeout=0 的中继端永挂
    $frames = \Tests\Mocks\RedisMock::peekStream(StreamEmitter::eventsKey('ghost-exp'));
    expect($frames)->not->toBe([]);
    expect($frames[count($frames) - 1][1]['event'])->toBe('error');
});
