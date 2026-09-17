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
    private const RESTART_IDLE_SECONDS = 180;
    /** 本生命周期至少处理过多少任务才允许自回收（低流量时 RSS 本就低，不必重启） */
    private const RESTART_MIN_PROCESSED = 50;
    /** 本生命周期处理任务上限：达到后一旦当前无在途任务立即退出，由 Swoole manager 自动重启释放 RSS */
    private const RESTART_MAX_PROCESSED = 500;
    /** 进程占用 PHP 堆内存达到此阈值（字节）且当前无在途任务时立即自回收（256MB） */
    private const RESTART_MAX_MEMORY_BYTES = 268435456;

    /** 在途任务数（单进程多协程共享；Swoole 单线程模型下普通 static 即可） */
    private static int $busyJobs = 0;
    /** 本进程生命周期累计处理任务数 */
    private static int $processedJobs = 0;

    private bool $running = true;
    private bool $draining = false;
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
        $activeConsumers = ['reclaimer'];
        for ($i = 0; $i < $max; $i++) {
            // 固定 worker-0, worker-1 命名：避免每次进程自回收/重启产生带 PID 的僵尸 consumer 堆积
            $consumer = 'worker-' . $i;
            $activeConsumers[] = $consumer;
            Coroutine::create(function () use ($consumer): void {
                $this->consumeLoop($consumer);
            });
        }

        // 启动时清理历史遗留的僵尸 consumers
        $this->cleanStaleConsumers($activeConsumers);

        Coroutine::create(function () use ($activeConsumers): void {
            $this->reclaimLoop($activeConsumers);
        });

        $drainDeadline = null;
        while ($this->running) {
            sleep(5);
            $this->maybeRecycle();
            if ($this->draining) {
                if ($drainDeadline === null) {
                    $drainDeadline = time() + 30; // 30 秒排空宽限期
                } elseif (time() > $drainDeadline) {
                    \App\Syslog::error(
                        'AiQueue',
                        sprintf('consumer drain timeout after 30s with %d in-flight jobs, forcing exit for restart', self::$busyJobs)
                    );
                    $this->running = false;
                    exit(0);
                }
            }
        }
    }

    /**
     * 清理消费者组内遗留的历史僵尸消费者。
     *
     * 避免因历史包含 PID 的命名方式（如 worker-112-0）或历史崩溃在组内残留上百个
     * 僵尸 consumer，导致 XINFO / XPENDING 遍历开销倍增。
     *
     * @param array<int, string> $activeConsumers
     */
    private function cleanStaleConsumers(array $activeConsumers): void
    {
        try {
            $consumers = RedisStreams::xInfoConsumers(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP);
            foreach ($consumers as $info) {
                $name = (string) ($info['name'] ?? '');
                $pending = (int) ($info['pending'] ?? 0);
                if ($name === '' || in_array($name, $activeConsumers, true)) {
                    continue;
                }
                // 仅当该 consumer 名下无 pending 消息时安全注销；
                // 若仍有 pending，留待 XAUTOCLAIM 转移并 ACK 后在后续周期清理
                if ($pending === 0) {
                    RedisStreams::xGroupDelConsumer(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP, $name);
                }
            }
        } catch (\Throwable $e) {
            \App\Syslog::error('AiQueue', 'clean stale consumers failed: ' . $e->getMessage());
        }
    }

    /**
     * 获取当前进程操作系统物理常驻内存（VmRSS 字节数）。
     *
     * 堆碎片化主要体现在操作系统分配的 arena/chunk 虚拟页面上；
     * 在调用 gc_mem_caches() 后，Zend 引擎内存统计（memory_get_usage）
     * 会归还到进程分配器内部 free list 并显示为仅几 MB，无法反映真实 RSS；
     * 故优先读取 /proc/self/status 的 VmRSS，非 Linux 或读取失败时回退 real_usage。
     */
    public static function getRssMemoryBytes(): int
    {
        $status = @file_get_contents('/proc/self/status');
        if ($status !== false && preg_match('/VmRSS:\s+(\d+)\s+kB/i', $status, $m)) {
            return ((int) $m[1]) * 1024;
        }
        return memory_get_usage(true);
    }

    /**
     * 自回收判定与排空机制：
     *
     * 阶段一（Trigger）：检查是否满足三项自回收条件之一：
     * 1. 任务量硬上限：累计处理达 RESTART_MAX_PROCESSED（500）；
     * 2. 物理内存超标：处理达最低阈值（50）且物理 RSS ≥ RESTART_MAX_MEMORY_BYTES（256MB）；
     * 3. 周期空闲回落：处理达最低阈值（50）且队列排队深度为 0 且连续空闲 ≥ RESTART_IDLE_SECONDS（180s）。
     * 满足任意条件即置 $draining = true，所有消费协程立即停止从 Redis 取新任务。
     *
     * 阶段二（Drain & Exit）：
     * 若已处于排空状态，等待在途任务完成（self::$busyJobs === 0）。
     * 在途任务清空后记录 Syslog 并调用 exit(0) 退出进程，由 Swoole Manager 重新拉起干净进程（RSS 重置）；
     * 若在途任务排空超时（由 handle 维护），超时强行退出。
     */
    private function maybeRecycle(): void
    {
        if (!$this->draining) {
            $reason = null;
            $rssBytes = self::getRssMemoryBytes();

            // 1. 处理量硬上限自回收
            if (self::$processedJobs >= self::RESTART_MAX_PROCESSED) {
                $reason = sprintf(
                    'processed=%d >= %d (max requests limit)',
                    self::$processedJobs,
                    self::RESTART_MAX_PROCESSED
                );
            } elseif (self::$processedJobs >= self::RESTART_MIN_PROCESSED && $rssBytes >= self::RESTART_MAX_MEMORY_BYTES) {
                // 2. 真实物理常驻内存阈值自回收
                $reason = sprintf(
                    'rss=%dMB >= %dMB, processed=%d (memory limit)',
                    intdiv($rssBytes, 1048576),
                    intdiv(self::RESTART_MAX_MEMORY_BYTES, 1048576),
                    self::$processedJobs
                );
            } elseif (self::$processedJobs >= self::RESTART_MIN_PROCESSED && (time() - $this->lastBusyAt >= self::RESTART_IDLE_SECONDS)) {
                // 3. 周期空闲自回收
                try {
                    if (AnalysisQueue::queueDepth() === 0) {
                        $reason = sprintf(
                            'idle>=%ds, processed=%d (idle timeout)',
                            self::RESTART_IDLE_SECONDS,
                            self::$processedJobs
                        );
                    } else {
                        $this->lastBusyAt = time();
                    }
                } catch (\Throwable) {
                }
            }

            if ($reason !== null) {
                $this->draining = true;
                \App\Syslog::error(
                    'AiQueue',
                    sprintf('consumer initiating graceful drain for recycle: %s, activeBusyJobs=%d', $reason, self::$busyJobs)
                );
            }
        }

        // 处于排空状态时：若在途任务已归零，执行优雅退出
        if ($this->draining && self::$busyJobs === 0) {
            \App\Syslog::error(
                'AiQueue',
                sprintf(
                    'consumer drain complete: processed=%d, rss=%dMB, exiting for manager restart (RSS reset)',
                    self::$processedJobs,
                    intdiv(self::getRssMemoryBytes(), 1048576)
                )
            );
            $this->running = false;
            exit(0);
        }
    }

    public function consumeLoop(string $consumer): void
    {
        while ($this->running && !$this->draining) {
            try {
                foreach (RedisStreams::xReadGroup(AnalysisQueue::GROUP, $consumer, AnalysisQueue::QUEUE_KEY, 5000) as [$id, $fields]) {
                    $this->runJob($id, (string) ($fields['jobId'] ?? ''));
                    if ($this->draining) {
                        break;
                    }
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

    /**
     * @param array<int, string> $activeConsumers
     */
    public function reclaimLoop(array $activeConsumers = ['reclaimer']): void
    {
        $cfg = AnalysisQueue::config();
        $intervalMs = max(10000, intdiv(max(1, (int) $cfg['claimIdleMs']), 2));
        $cursor = '0-0';
        while ($this->running && !$this->draining) {
            usleep($intervalMs * 1000);
            try {
                $this->cleanStaleConsumers($activeConsumers);
                foreach (RedisStreams::xAutoClaim(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP, 'reclaimer', (int) $cfg['claimIdleMs'], 10, $cursor) as [$id, $fields]) {
                    // 死信计数：同一条目最多重试 3 次，防止毒药/无载荷条目极端无限重投
                    $reclaimCountKey = 'ai:job:reclaim:' . $id;
                    $times = RedisStreams::incr($reclaimCountKey);
                    RedisStreams::expire($reclaimCountKey, 3600);
                    if ($times > 3) {
                        \App\Syslog::error('AiQueue', "job entry {$id} reached max delivery limit ({$times}), dropping poisoned entry");
                        RedisStreams::xAck(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP, $id);
                        RedisStreams::xDel(AnalysisQueue::QUEUE_KEY, $id);
                        RedisStreams::del($reclaimCountKey);
                        continue;
                    }
                    $this->runJob($id, (string) ($fields['jobId'] ?? ''));
                    if ($this->draining) {
                        break;
                    }
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

            // 任务结束主动回收垃圾并释放未使用的 Zend arena 内存缓存，减缓堆碎片化
            gc_collect_cycles();
            if (function_exists('gc_mem_caches')) {
                gc_mem_caches();
            }

            // 单个任务完成后主动触发检查：
            // 1. 若已处于排空阶段且此为最后一个在途任务，立即退出，无需等待 5s 轮询；
            // 2. 若任务数已达上限或真实 RSS 超过阈值，立即开启排空阶段。
            if ($this->draining && self::$busyJobs === 0) {
                $this->maybeRecycle();
            } elseif (!$this->draining && (self::$processedJobs >= self::RESTART_MAX_PROCESSED || self::getRssMemoryBytes() >= self::RESTART_MAX_MEMORY_BYTES)) {
                $this->maybeRecycle();
            }
        }
    }
}
