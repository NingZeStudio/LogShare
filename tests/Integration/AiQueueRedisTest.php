<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Ai\AnalysisQueue;
use App\Client\RedisStreams;
use App\Sse\StreamEmitter;
use Tests\HttpTestCase;

/**
 * AI 微队列对真实 Redis 的集成验证（Streams 命令面、消费组、XAUTOCLAIM）。
 *
 * 本地 Termux 无 ext-redis / redis 服务时整体跳过；CI（redis:7-alpine 服务 +
 * redis 扩展）执行。单元层（AnalysisQueueTest）以 RedisMock 覆盖同等语义。
 */
class AiQueueRedisTest extends HttpTestCase
{
    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            RedisStreams::xLen(AnalysisQueue::QUEUE_KEY . ':probe-nonexistent');
            $this->redisAvailable = extension_loaded('redis');
        } catch (\Throwable $e) {
            $this->redisAvailable = false;
        }

        $this->cleanupQueueKeys();
    }

    protected function tearDown(): void
    {
        $this->cleanupQueueKeys();
        parent::tearDown();
    }

    private function requireRedis(): void
    {
        if (!$this->redisAvailable) {
            $this->markTestSkipped('Redis unavailable — skipping AI queue integration test');
        }
    }

    private function cleanupQueueKeys(): void
    {
        try {
            RedisStreams::del(AnalysisQueue::QUEUE_KEY);
        } catch (\Throwable $e) {
            // Redis 不可用时忽略
        }
    }

    private function setQueueConfig(array $queue): void
    {
        $ref = new \ReflectionClass(\App\Config::class);
        $prop = $ref->getProperty('data');
        $data = $prop->getValue();
        $data['ai']['queue'] = $queue;
        $data['ai']['agent']['enabled'] = false;
        $prop->setValue(null, $data);
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

        // 事件流以 error 收尾且已设 TTL；条目已 ACK（无残留 pending）
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
}
