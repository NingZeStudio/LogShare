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
 *
 * 空闲自回收：常驻进程的 mysqlnd/phpredis 连接缓冲、zend arena 空闲 chunk、
 * 协程栈池等在任务洪峰后会以 live set 形态驻留（jemalloc 也无法替应用归还，
 * 2026-09-09 线上实测空闲 RSS 919MB 不回落）。处理量达到阈值后，一旦空闲
 * （无在途 job、队列 pending 为 0）持续 RESTART_IDLE_SECONDS，进程主动 exit，
 * 由 Swoole manager 自动拉起（与 php-fpm pm.max_requests 同思路），RSS 归零。
 * 重启间隔天然 ≥ RESTART_IDLE_SECONDS，无重启风暴风险。
 */
#[Process(name: 'ai-queue-consumer')]
class AiQueueConsumer extends AbstractProcess
{
    public string $name = 'ai-queue-consumer';

    /** 空闲多久后自回收（秒） */
    private const RESTART_IDLE_SECONDS = 300;
    /** 本生命周期至少处理过多少任务才允许自回收（低流量时 RSS 本就低，不必重启） */
    private const RESTART_MIN_PROCESSED = 50;

    /** 在途任务数（单进程多协程共享；Swoole 单线程模型下普通 static 即可） */
    private static int $busyJobs = 0;
    /** 本进程生命周期累计处理任务数 */
    private static int $processedJobs = 0;

    private bool $running = true;
    private int $lastBusyAt = 0;

    /**
     * 队列关闭时不启动（App\Config 在 core.php 已加载，事件触发期读取安全）。
     *
     * @param mixed $server 父类签名透传的 Swoole Server；此处不使用，
     *                      显式 mixed 以免静态分析环境（无 swoole 扩展）解析不到类型
     */
    public function isEnable($server): bool
    {
        return AnalysisQueue::enabled();
    }

    public function handle(): void
    {
        $cfg = AnalysisQueue::config();
        $this->lastBusyAt = time();

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
            sleep(5);
            $this->maybeRecycle();
        }
    }

    /**
     * 空闲自回收判定：无在途任务 + 队列无 pending + 处理量达标 + 空闲超时。
     * Redis 故障时 queueDepth 抛出，跳过本轮（fail-open 语义一致）。
     */
    private function maybeRecycle(): void
    {
        if (self::$busyJobs > 0) {
            $this->lastBusyAt = time();
            return;
        }
        if (self::$processedJobs < self::RESTART_MIN_PROCESSED) {
            return;
        }
        if (time() - $this->lastBusyAt < self::RESTART_IDLE_SECONDS) {
            return;
        }
        try {
            if (AnalysisQueue::queueDepth() > 0) {
                $this->lastBusyAt = time();
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        \App\Syslog::error(
            'AiQueue',
            sprintf(
                'consumer idle recycle: processed=%d, idle>=%ds, exiting for manager restart (RSS reset)',
                self::$processedJobs,
                self::RESTART_IDLE_SECONDS
            )
        );
        exit(0);
    }

    public function consumeLoop(string $consumer): void
    {
        while ($this->running) {
            try {
                foreach (RedisStreams::xReadGroup(AnalysisQueue::GROUP, $consumer, AnalysisQueue::QUEUE_KEY, 5000) as [$id, $fields]) {
                    $this->runJob($id, (string) ($fields['jobId'] ?? ''));
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
        $cursor = '0-0';
        while ($this->running) {
            usleep($intervalMs * 1000);
            try {
                foreach (RedisStreams::xAutoClaim(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP, 'reclaimer', (int) $cfg['claimIdleMs'], 10, $cursor) as [$id, $fields]) {
                    $this->runJob($id, (string) ($fields['jobId'] ?? ''));
                }
            } catch (\Throwable $e) {
                $cursor = '0-0';
                \App\Syslog::error('AiQueue', 'reclaim loop error: ' . $e->getMessage());
            }
        }
    }

    /** 包一层在途计数：maybeRecycle 依赖它判断「无任务在跑」才可安全 exit */
    private function runJob(string $entryId, string $jobId): void
    {
        self::$busyJobs++;
        try {
            AnalysisQueue::consumeJob($entryId, $jobId);
        } finally {
            self::$busyJobs--;
            self::$processedJobs++;
            $this->lastBusyAt = time();
        }
    }
}
