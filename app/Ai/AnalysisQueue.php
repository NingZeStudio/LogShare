<?php

declare(strict_types=1);

namespace App\Ai;

use App\Client\AIClient;
use App\Client\RedisStreams;
use App\Agent\LogAgent;
use App\Exception\ClientDisconnectedException;
use App\Sse\SseWriter;
use App\Sse\StreamEmitter;
use Hyperf\HttpServer\Response;

/**
 * AI 分析微队列（Redis Streams 消费者组）。
 *
 * 生产者侧（控制器）：入队 job → SSE 中继 job 事件流；队列满直接 429。
 * 消费者侧（AiQueueConsumer 进程）：XREADGROUP 取 job → 以 StreamEmitter 执行
 * 分析 → 帧入事件流、结果写缓存 → XACK。
 *
 * 已确认语义：
 *  - 全量分析走队列（enabled 时），Redis 故障 fail-open 回退 inline；
 *  - 客户端断连只结束中继，任务继续跑完并写缓存；
 *  - 队列满（XLEN ≥ maxQueue）直接 429 + Retry-After，不做超深排队。
 *
 * 日志正文不进 Stream：只存短 TTL 的 payload 键（gzip），XACK 后立即删除。
 */
final class AnalysisQueue
{
    public const QUEUE_KEY = 'ai:analyse:queue';
    public const GROUP = 'ai-analysers';

    private const DEFAULTS = [
        'enabled' => false,
        'maxConcurrent' => 2,
        'maxQueue' => 50,
        'waitTimeout' => 300,
        'claimIdleMs' => 120000,
        'jobTtl' => 600,
        'failOpen' => true,
    ];

    /** SSE 帧统一 JSON 编码 flags（与 LogAgent 一致） */
    private const FRAME_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @return array{enabled: bool, maxConcurrent: int, maxQueue: int, waitTimeout: int, claimIdleMs: int, jobTtl: int, failOpen: bool}
     */
    public static function config(): array
    {
        $cfg = \App\Config::Get('ai')['queue'] ?? [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
        return array_merge(self::DEFAULTS, array_intersect_key($cfg, self::DEFAULTS));
    }

    public static function enabled(): bool
    {
        return self::config()['enabled'] === true;
    }

    /**
     * payload / active 映射 / 运行锁的存活秒数。
     *
     * 有排队超时（waitTimeout > 0）时取 jobTtl + waitTimeout，覆盖「排队等待 + 执行」
     * 全程；无排队超时（waitTimeout <= 0）时中继端无限等待，jobTtl 本身即任务总寿命，
     * 直接取 jobTtl（此时应把 jobTtl 配得足够长，如 15 天）。
     */
    public static function jobLifetime(): int
    {
        $cfg = self::config();
        $wait = (int) $cfg['waitTimeout'];
        return $wait > 0 ? (int) $cfg['jobTtl'] + $wait : (int) $cfg['jobTtl'];
    }

    /**
     * 与 inline 路径一致的缓存键：agent 路径用原键，legacy 路径带 analysis-v2: 前缀。
     * 生产者预检与消费者回源共用，避免两处前缀逻辑演化不一致。
     * agent 判定与 AbstractController::runAiAnalysis 同口径（宽松真值）。
     */
    public static function cacheKeyFor(string $cacheKey): string
    {
        $agent = (bool) (\App\Config::Get('ai')['agent']['enabled'] ?? false);
        return $agent ? $cacheKey : 'analysis-v2:' . $cacheKey;
    }

    /**
     * 真实排队深度 = 未投递条目（组 lag）+ 已投递未确认（in-flight pending）。
     *
     * 不能用 XLEN：XACK 不从 Stream 移除条目，XLEN 是累计消息数——
     * 用它判满会导致「消费完毕但计数不减」的永久 429（2026-09-08 线上事故）。
     * 消费组尚未创建（NOGROUP，如消费者进程未启动）时保守回退 XLEN。
     */
    public static function queueDepth(): int
    {
        try {
            $lag = RedisStreams::xGroupLag(self::QUEUE_KEY, self::GROUP);
            $pending = RedisStreams::xPendingCount(self::QUEUE_KEY, self::GROUP);
            return max(0, (int) ($lag ?? 0)) + max(0, $pending);
        } catch (\Throwable $e) {
            // NOGROUP：无人消费过，全部条目都在排队
            return RedisStreams::xLen(self::QUEUE_KEY);
        }
    }

    public static function payloadKey(string $jobId): string
    {
        return 'ai:job:' . $jobId . ':payload';
    }

    private static function runningKey(string $jobId): string
    {
        return 'ai:job:' . $jobId . ':running';
    }

    private static function activeKey(string $cacheKey): string
    {
        return 'ai:job:active:' . hash('sha256', $cacheKey);
    }

    /**
     * 入队一个分析 job；同 cacheKey 已有活跃 job 时挂接而不重复入队。
     *
     * @return array{jobId: string, position: int, attached: bool}
     * @throws \Exception Redis 不可用（调用方决定 fail-open）
     */
    public static function enqueue(string $content, string $cacheKey, ?string $logId, int $cacheTTL): array
    {
        $ttl = self::jobLifetime();

        $existing = RedisStreams::get(self::activeKey($cacheKey));
        if ($existing !== null && $existing !== '') {
            return ['jobId' => $existing, 'position' => self::queueDepth(), 'attached' => true];
        }

        $jobId = bin2hex(random_bytes(8));
        // SET NX 失败说明竞态窗口内有请求抢先注册：挂接它的 job
        if (!RedisStreams::setNxEx(self::activeKey($cacheKey), $jobId, $ttl)) {
            $existing = RedisStreams::get(self::activeKey($cacheKey));
            if ($existing !== null && $existing !== '') {
                return ['jobId' => $existing, 'position' => self::queueDepth(), 'attached' => true];
            }
        }

        $payload = json_encode([
            'jobId' => $jobId,
            'content' => $content,
            'cacheKey' => $cacheKey,
            'logId' => $logId,
            'cacheTTL' => $cacheTTL,
        ], JSON_UNESCAPED_UNICODE);
        RedisStreams::set(self::payloadKey($jobId), (string) gzcompress((string) $payload, 6), $ttl);
        RedisStreams::xAdd(self::QUEUE_KEY, ['jobId' => $jobId]);

        return ['jobId' => $jobId, 'position' => self::queueDepth(), 'attached' => false];
    }

    /**
     * SSE 中继：把 job 事件流原样转发给客户端，直至 done/error；
     * waitTimeout > 0 时超时以 error 收尾，waitTimeout <= 0 表示无排队超时（无限等待）。
     *
     * 客户端断连（SseWriter 抛 ClientDisconnectedException）只结束中继，
     * 任务继续执行并写缓存；中继自身异常以 error 帧收尾，不向上逃逸。
     */
    public static function relay(string $jobId, ?Response $response): void
    {
        $cfg = self::config();
        $eventsKey = StreamEmitter::eventsKey($jobId);
        SseWriter::begin($response);
        $finished = false;
        try {
            SseWriter::write('event: status' . "\ndata: " . json_encode(
                ['type' => 'queued', 'position' => self::queueDepth()],
                self::FRAME_JSON_FLAGS
            ) . "\n\n");

            $waitTimeout = (int) $cfg['waitTimeout'];
            $deadline = $waitTimeout > 0 ? time() + $waitTimeout : null;
            $lastId = '0-0';
            while (!$finished && ($deadline === null || time() < $deadline)) {
                $entries = RedisStreams::xRead($eventsKey, $lastId, 2000);
                if ($entries === []) {
                    // 服务端未阻塞（测试 mock）时防空转
                    usleep(50000);
                    continue;
                }
                foreach ($entries as [$id, $fields]) {
                    $lastId = $id;
                    $event = (string) ($fields['event'] ?? '');
                    $data = (string) ($fields['data'] ?? '');
                    SseWriter::write(($event === '' ? 'data: ' : "event: {$event}\ndata: ") . $data . "\n\n");
                    if ($event === 'done' || $event === 'error') {
                        $finished = true;
                        break;
                    }
                }
            }

            if (!$finished) {
                SseWriter::write("event: error\ndata: " . json_encode(
                    ['error' => '分析排队等待超时，请稍后重试。'],
                    self::FRAME_JSON_FLAGS
                ) . "\n\n");
            }
        } catch (ClientDisconnectedException $e) {
            // 断连语义（已确认）：任务继续跑完并缓存，这里只停止中继
            \App\Syslog::error('AiQueue', 'relay client disconnected, job continues: ' . $jobId);
        } catch (\Throwable $e) {
            \App\Syslog::error('AiQueue', 'relay failed for job ' . $jobId . ': ' . $e->getMessage());
            try {
                SseWriter::write("event: error\ndata: " . json_encode(
                    ['error' => '分析队列暂不可用，请稍后重试。'],
                    self::FRAME_JSON_FLAGS
                ) . "\n\n");
            } catch (\Throwable $ignored) {
            }
        } finally {
            SseWriter::end();
        }
    }

    /**
     * 消费者侧执行单个 job。
     *
     * @param string $entryId 队列 Stream 条目 id（XACK 用）
     * @param string $jobId   业务 job id（payload/事件流键）
     */
    public static function consumeJob(string $entryId, string $jobId): void
    {
        $cfg = self::config();
        if ($jobId === '') {
            RedisStreams::xAck(self::QUEUE_KEY, self::GROUP, $entryId);
            return;
        }

        $payloadRaw = RedisStreams::get(self::payloadKey($jobId));
        if ($payloadRaw === null) {
            // payload 已过期：补发终态帧收尾，否则 waitTimeout=0 的中继端会无限等待
            try {
                (new StreamEmitter($jobId, (int) $cfg['jobTtl']))->finish('error', json_encode(
                    ['error' => '分析任务已超过排队存活时限，请重新提交。'],
                    self::FRAME_JSON_FLAGS
                ));
            } catch (\Throwable $e) {
                \App\Syslog::error('AiQueue', 'job ' . $jobId . ' expiry notice failed: ' . $e->getMessage());
            }
            RedisStreams::xAck(self::QUEUE_KEY, self::GROUP, $entryId);
            return;
        }

        // job 级运行锁：XAUTOCLAIM 重投时原消费者可能仍在慢执行，锁被持有则跳过，
        // 条目保持 pending 等待下一轮回收，避免双跑与事件流交错。
        // TTL 覆盖 payload 生命周期：payload 过期后重投本就会被丢弃，锁无需更长。
        if (!RedisStreams::setNxEx(self::runningKey($jobId), $entryId, self::jobLifetime())) {
            return;
        }

        $cleanupCacheKey = null;
        try {
            $decoded = json_decode((string) gzuncompress($payloadRaw), true);
            if (!is_array($decoded) || !isset($decoded['content'], $decoded['cacheKey'])) {
                throw new \RuntimeException('malformed job payload');
            }
            $cleanupCacheKey = (string) $decoded['cacheKey'];

            $emitter = new StreamEmitter($jobId, (int) $cfg['jobTtl']);
            $agentConfig = \App\Config::Get('ai')['agent'] ?? [];
            if ((bool) ($agentConfig['enabled'] ?? false)) {
                LogAgent::analyze((string) $decoded['content'], [
                    'cacheKey' => (string) $decoded['cacheKey'],
                    'cacheTTL' => (int) ($decoded['cacheTTL'] ?? 1800),
                    'logId' => $decoded['logId'] ?? null,
                    'emitter' => $emitter,
                ]);
            } else {
                AIClient::analyzeStream(
                    (string) $decoded['content'],
                    (string) $decoded['cacheKey'],
                    (int) ($decoded['cacheTTL'] ?? 1800),
                    null,
                    $emitter
                );
            }
        } catch (\Throwable $e) {
            // LogAgent/AIClient 正常路径已在流内收敛异常；到这里的是载荷损坏等
            // 执行框架故障，对外只给固定文案，细节仅进 Syslog
            \App\Syslog::error('AiQueue', 'job ' . $jobId . ' failed: ' . $e->getMessage());
            try {
                (new StreamEmitter($jobId, (int) $cfg['jobTtl']))->finish('error', json_encode(
                    ['error' => '分析执行失败，请稍后重试。'],
                    self::FRAME_JSON_FLAGS
                ));
            } catch (\Throwable $ignored) {
            }
        } finally {
            try {
                RedisStreams::del(self::payloadKey($jobId));
                RedisStreams::del(self::runningKey($jobId));
                if ($cleanupCacheKey !== null) {
                    RedisStreams::del(self::activeKey($cleanupCacheKey));
                }
                RedisStreams::xAck(self::QUEUE_KEY, self::GROUP, $entryId);
            } catch (\Throwable $e) {
                \App\Syslog::error('AiQueue', 'job ' . $jobId . ' cleanup failed: ' . $e->getMessage());
            }
        }
    }
}
