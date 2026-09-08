<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Ai\AnalysisQueue;
use App\Client\RedisStreams;
use App\Sse\StreamEmitter;
use Tests\HttpTestCase;

/**
 * AI 微队列对真实 Redis 的集成验证（Streams 命令面、消费组、XAUTOCLAIM、
 * 控制器胶合层 429/fail-open）。
 *
 * 本地 Termux 无 ext-redis / redis 服务时整体跳过；CI（redis:7-alpine 服务 +
 * redis 扩展）执行。单元层（AnalysisQueueTest）以 RedisMock 覆盖同等语义，
 * 两环境互斥执行，不重复也不留空档。
 */
class AiQueueRedisTest extends HttpTestCase
{
    private bool $redisAvailable = false;
    private ?array $origConfig = null;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            RedisStreams::xLen(AnalysisQueue::QUEUE_KEY . ':probe-nonexistent');
            $this->redisAvailable = extension_loaded('redis');
        } catch (\Throwable $e) {
            $this->redisAvailable = false;
        }

        $ref = new \ReflectionClass(\App\Config::class);
        $this->origConfig = $ref->getProperty('data')->getValue();
        $this->cleanupQueueKeys();
    }

    protected function tearDown(): void
    {
        $this->cleanupQueueKeys();
        $ref = new \ReflectionClass(\App\Config::class);
        $ref->getProperty('data')->setValue(null, $this->origConfig);
        parent::tearDown();
    }

    private function requireRedis(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis unavailable — skipping AI queue integration test');
        }
    }

    private function setQueueConfig(array $queue, bool $agent = false): void
    {
        $ref = new \ReflectionClass(\App\Config::class);
        $data = $ref->getProperty('data')->getValue();
        $data['ai']['queue'] = $queue;
        $data['ai']['agent']['enabled'] = $agent;
        $ref->getProperty('data')->setValue(null, $data);
    }

    /** 把 Redis 指向不可达端口：RedisStreams 一律抛连接异常（fail-open 场景） */
    private function setRedisUnreachable(): void
    {
        $ref = new \ReflectionClass(\App\Config::class);
        $data = $ref->getProperty('data')->getValue();
        $data['cache']['enabled'] = true;
        $data['cache']['redis'] = ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.5];
        $ref->getProperty('data')->setValue(null, $data);
    }

    private function cleanupQueueKeys(): void
    {
        try {
            RedisStreams::del(AnalysisQueue::QUEUE_KEY);
            foreach (['int1', 'int2', 'int3', 'relay1', 'full429', 'nofailopen', 'cached'] as $suffix) {
                $cacheKey = 'ai:analysis:' . $suffix;
                RedisStreams::del('ai:job:active:' . hash('sha256', $cacheKey));
                RedisStreams::del('analysis-v2:' . $cacheKey);
            }
        } catch (\Throwable $e) {
            // Redis 不可用时忽略
        }
    }

    private function controller(): object
    {
        return (new \ReflectionClass(QueueIntegrationController::class))->newInstanceWithoutConstructor();
    }

    private function invokeRunAiAnalysis(object $controller, string $content, string $cacheKey): \Psr\Http\Message\ResponseInterface
    {
        $m = (new \ReflectionClass(QueueIntegrationController::class))->getMethod('runAiAnalysis');
        return $m->invoke($controller, $content, $cacheKey);
    }

    public function testEnqueueConsumeAckCycle(): void
    {
        $this->requireRedis();
        $this->setQueueConfig(['enabled' => true, 'jobTtl' => 60, 'waitTimeout' => 5]);

        $job = AnalysisQueue::enqueue('log body', 'ai:analysis:int1', null, 1800);
        $this->assertSame(1, AnalysisQueue::queueDepth());

        RedisStreams::xGroupCreate(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP);
        $entries = RedisStreams::xReadGroup(AnalysisQueue::GROUP, 'itest', AnalysisQueue::QUEUE_KEY, 1000);
        $this->assertCount(1, $entries);

        // agent 关闭 → legacy 路径；无 AI Key 时 analyzeStream 收敛为流内 error 帧
        AnalysisQueue::consumeJob($entries[0][0], $job['jobId']);

        $this->assertNull(RedisStreams::get(AnalysisQueue::payloadKey($job['jobId'])));
        $this->assertNull(RedisStreams::get('ai:job:active:' . hash('sha256', 'ai:analysis:int1')));

        // 事件流以 error 收尾；条目已 ACK（无残留 pending）
        $frames = RedisStreams::xRead(StreamEmitter::eventsKey($job['jobId']), '0-0', 1);
        $this->assertNotEmpty($frames);
        $last = $frames[count($frames) - 1];
        $this->assertSame('error', $last[1]['event']);

        $claimed = RedisStreams::xAutoClaim(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP, 'probe', 0);
        $this->assertSame([], $claimed);
    }

    public function testDedupAndDepth(): void
    {
        $this->requireRedis();
        $this->setQueueConfig(['enabled' => true, 'jobTtl' => 60, 'waitTimeout' => 5]);

        $first = AnalysisQueue::enqueue('a', 'ai:analysis:int2', null, 1800);
        $second = AnalysisQueue::enqueue('b', 'ai:analysis:int2', null, 1800);
        $this->assertSame($first['jobId'], $second['jobId']);
        $this->assertTrue($second['attached']);
        $this->assertSame(1, AnalysisQueue::queueDepth());
    }

    public function testAutoClaimReclaimsPendingEntry(): void
    {
        $this->requireRedis();
        $this->setQueueConfig(['enabled' => true, 'jobTtl' => 60, 'waitTimeout' => 5]);

        $job = AnalysisQueue::enqueue('x', 'ai:analysis:int3', null, 1800);
        RedisStreams::xGroupCreate(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP);
        $entries = RedisStreams::xReadGroup(AnalysisQueue::GROUP, 'dead-worker', AnalysisQueue::QUEUE_KEY, 1000);
        $this->assertCount(1, $entries);

        // min-idle 0：模拟原消费者已死，回收协程应能重新拿到该条目与 jobId
        $claimed = RedisStreams::xAutoClaim(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP, 'reclaimer', 0);
        $this->assertCount(1, $claimed);
        $this->assertSame($job['jobId'], $claimed[0][1]['jobId']);

        // 运行锁仍持有（consumeJob 尚未执行）时重投应跳过而不双跑
        RedisStreams::setNxEx('ai:job:' . $job['jobId'] . ':running', 'x', 60);
        AnalysisQueue::consumeJob($claimed[0][0], $job['jobId']);
        $this->assertNotNull(RedisStreams::get(AnalysisQueue::payloadKey($job['jobId'])));
    }

    public function testRelayForwardsFramesUntilDone(): void
    {
        $this->requireRedis();
        $this->setQueueConfig(['enabled' => true, 'jobTtl' => 60, 'waitTimeout' => 5]);

        $events = StreamEmitter::eventsKey('relay-job-1');
        RedisStreams::del($events);
        RedisStreams::xAdd($events, ['event' => '', 'data' => '{"choices":[{"delta":{"content":"hi"}}]}']);
        RedisStreams::xAdd($events, ['event' => 'done', 'data' => '{"status":"completed"}']);
        RedisStreams::xAdd($events, ['event' => 'status', 'data' => '{"type":"thinking","delta":"after-done"}']);

        ob_start();
        AnalysisQueue::relay('relay-job-1', null);
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('event: status' . "\n" . 'data: {"type":"queued"', $out);
        $this->assertStringContainsString("data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\n", $out);
        $this->assertStringContainsString("event: done\ndata: {\"status\":\"completed\"}\n\n", $out);
        // done 之后的帧不得转发
        $this->assertStringNotContainsString('after-done', $out);
    }

    public function testRelayEmitsTimeoutErrorFrame(): void
    {
        $this->requireRedis();
        $this->setQueueConfig(['enabled' => true, 'jobTtl' => 60, 'waitTimeout' => 1]);

        ob_start();
        AnalysisQueue::relay('missing-job', null);
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('event: error', $out);
        $this->assertStringContainsString('排队等待超时', $out);
    }

    public function testQueueFullReturns429WithRetryAfter(): void
    {
        $this->requireRedis();
        $this->setQueueConfig(['enabled' => true, 'maxQueue' => 2, 'jobTtl' => 60, 'waitTimeout' => 5]);
        RedisStreams::xAdd(AnalysisQueue::QUEUE_KEY, ['jobId' => 'a']);
        RedisStreams::xAdd(AnalysisQueue::QUEUE_KEY, ['jobId' => 'b']);

        $resp = $this->invokeRunAiAnalysis($this->controller(), 'log body', 'ai:analysis:full429');
        $this->assertSame(429, $resp->getStatusCode());
        $this->assertSame('30', $resp->getHeaderLine('Retry-After'));
        // 未入队：深度不变
        $this->assertSame(2, AnalysisQueue::queueDepth());
    }

    public function testRedisUnavailableWithoutFailOpenRaises503(): void
    {
        $this->requireRedis();
        $this->setQueueConfig(['enabled' => true, 'failOpen' => false, 'jobTtl' => 60, 'waitTimeout' => 5]);
        $this->setRedisUnreachable();

        $this->expectException(\App\ApiError::class);
        $this->invokeRunAiAnalysis($this->controller(), 'log body', 'ai:analysis:nofailopen');
    }

    public function testEnqueueThrowsWhenRedisUnreachableSoControllerCanFailOpen(): void
    {
        $this->requireRedis();
        $this->setQueueConfig(['enabled' => true, 'failOpen' => true, 'jobTtl' => 60, 'waitTimeout' => 5]);
        $this->setRedisUnreachable();

        // 控制器 catch 分支的输入：enqueue 必须抛可捕获异常（failOpen=true 时落到
        // inline 路径，等价于队列关闭——该路径由既有全量测试覆盖）
        $this->expectException(\Throwable::class);
        AnalysisQueue::enqueue('log body', 'ai:analysis:fallback', null, 1800);
    }

    public function testCacheHitSkipsQueue(): void
    {
        $this->requireRedis();
        $this->setQueueConfig(['enabled' => true, 'jobTtl' => 60, 'waitTimeout' => 5]);
        // 预置缓存（agent 关闭 → legacy 键带 analysis-v2: 前缀）
        \App\Cache\RedisCache::Set('analysis-v2:ai:analysis:cached', 'cached answer', 60);

        $m = (new \ReflectionClass(QueueIntegrationController::class))->getMethod('aiCacheHit');
        $this->assertTrue($m->invoke($this->controller(), 'ai:analysis:cached'));
        $this->assertFalse($m->invoke($this->controller(), 'ai:analysis:int1'));
        $this->assertSame(0, AnalysisQueue::queueDepth());
    }
}

/** 具体子类供反射实例化（newInstanceWithoutConstructor 跳过 DI 代理构造器） */
class QueueIntegrationController extends \App\Controller\AbstractController
{
}
