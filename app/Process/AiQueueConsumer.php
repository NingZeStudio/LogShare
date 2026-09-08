<?php

declare(strict_types=1);

namespace App\Process;

use App\Ai\AnalysisQueue;
use App\Client\RedisStreams;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\Annotation\Process;

/**
 * AI 分析微队列消费者进程（仅 ai.queue.enabled 时启动，见 isEnable）。
 *
 * 进程内派生 maxConcurrent 个消费协程（XREADGROUP 阻塞取新 job），外加一个
 * 回收协程周期 XAUTOCLAIM：消费者崩溃/卡死后空闲超过 claimIdleMs 的 pending
 * 条目被重投；重投撞上仍在慢执行的活 job 由 job 级运行锁挡住（见
 * AnalysisQueue::consumeJob），不会双跑。
 */
#[Process(name: 'ai-queue-consumer')]
class AiQueueConsumer extends AbstractProcess
{
    public string $name = 'ai-queue-consumer';

    private bool $running = true;

    /**
     * 队列关闭时不启动（App\Config 在 core.php 已加载，事件触发期读取安全）。
     */
    public function isEnable($server): bool
    {
        return AnalysisQueue::enabled();
    }

    public function handle(): void
    {
        $cfg = AnalysisQueue::config();

        try {
            RedisStreams::xGroupCreate(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP);
        } catch (\Throwable $e) {
            \App\Syslog::error('AiQueue', 'consumer group create failed, retrying in loop: ' . $e->getMessage());
        }

        $max = max(1, (int) $cfg['maxConcurrent']);
        for ($i = 0; $i < $max; $i++) {
            $consumer = 'worker-' . posix_getpid() . '-' . $i;
            Coroutine::create(function () use ($consumer): void {
                $this->consumeLoop($consumer);
            });
        }
        Coroutine::create(function (): void {
            $this->reclaimLoop();
        });

        while ($this->running) {
            sleep(1);
        }
    }

    public function consumeLoop(string $consumer): void
    {
        while ($this->running) {
            try {
                foreach (RedisStreams::xReadGroup(AnalysisQueue::GROUP, $consumer, AnalysisQueue::QUEUE_KEY, 5000) as [$id, $fields]) {
                    AnalysisQueue::consumeJob($id, (string) ($fields['jobId'] ?? ''));
                }
            } catch (\Throwable $e) {
                // NOGROUP（Redis 重启/flush）时重建消费组后继续
                if (str_contains($e->getMessage(), 'NOGROUP')) {
                    try {
                        RedisStreams::xGroupCreate(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP);
                    } catch (\Throwable $inner) {
                        \App\Syslog::error('AiQueue', 'group recreate failed: ' . $inner->getMessage());
                    }
                }
                \App\Syslog::error('AiQueue', 'consume loop error: ' . $e->getMessage());
                sleep(1);
            }
        }
    }

    public function reclaimLoop(): void
    {
        $cfg = AnalysisQueue::config();
        $intervalMs = max(10000, intdiv(max(1, (int) $cfg['claimIdleMs']), 2));
        while ($this->running) {
            usleep($intervalMs * 1000);
            try {
                foreach (RedisStreams::xAutoClaim(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP, 'reclaimer', (int) $cfg['claimIdleMs']) as [$id, $fields]) {
                    AnalysisQueue::consumeJob($id, (string) ($fields['jobId'] ?? ''));
                }
            } catch (\Throwable $e) {
                \App\Syslog::error('AiQueue', 'reclaim loop error: ' . $e->getMessage());
            }
        }
    }
}
