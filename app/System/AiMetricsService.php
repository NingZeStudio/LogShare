<?php

declare(strict_types=1);

namespace App\System;

use App\Ai\AnalysisQueue;
use App\Client\RedisClient;
use App\Client\RedisStreams;
use App\Config;

/**
 * AI 运营观测与微队列控制中心服务。
 *
 * 负责 AI 吞吐/耗时/Token/知识库 Topic 指标统计聚合、
 * 队列深度探查与死信监控、以及运维控制（暂停/恢复消费、清空队列）。
 */
final class AiMetricsService
{
    private const METRICS_FILE = '/runtime/ai_metrics.json';
    private const PAUSE_FILE = '/runtime/ai_queue_paused';
    private const DEAD_FILE = '/runtime/ai_dead_jobs.json';
    private const PAUSE_KEY = 'ai:queue:paused';
    private const DEAD_KEY = 'ai:jobs:dead';
    private const METRICS_PREFIX = 'ai:metrics:';

    /**
     * 记录一次 AI 分析调用指标。
     *
     * @param bool $success 是否成功完成
     * @param int $durationMs 耗时毫秒数
     * @param int $inputChars 输入字符估算
     * @param int $outputChars 输出字符估算
     * @param array<int, string> $toolsUsed 调用的工具名列表
     * @param array<int, string> $topicsHit RAG 召回命中的 topic 列表
     */
    public static function recordAnalysis(
        bool $success,
        int $durationMs,
        int $inputChars,
        int $outputChars,
        array $toolsUsed = [],
        array $topicsHit = []
    ): void {
        $now = time();
        $date = date('Y-m-d', $now);
        $dateKey = date('Ymd', $now);

        // 简易 Token 估算：通常英文 1 token ≈ 4 字符，中文字符约 1:1.5-2；按 char / 3.5 估算更接近真实
        $inTokens = max(1, (int) round($inputChars / 3.5));
        $outTokens = max(0, (int) round($outputChars / 3.5));
        $ragCalls = in_array('rag_search', $toolsUsed, true) ? 1 : 0;

        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                $hashKey = self::METRICS_PREFIX . 'summary:' . $dateKey;
                $redis->hIncrBy($hashKey, 'total_requests', 1);
                if ($success) {
                    $redis->hIncrBy($hashKey, 'success_requests', 1);
                } else {
                    $redis->hIncrBy($hashKey, 'failed_requests', 1);
                }
                $redis->hIncrBy($hashKey, 'total_duration_ms', max(0, $durationMs));
                $redis->hIncrBy($hashKey, 'input_tokens', $inTokens);
                $redis->hIncrBy($hashKey, 'output_tokens', $outTokens);
                if ($ragCalls > 0) {
                    $redis->hIncrBy($hashKey, 'rag_calls', $ragCalls);
                }
                // 设置 35 天过期，自动滚存
                $redis->expire($hashKey, 3024000);

                // 记录耗时用于分位数采样（按天列表存储，上限 2000 个采样）
                $durKey = self::METRICS_PREFIX . 'durations:' . $dateKey;
                $redis->lPush($durKey, (string) $durationMs);
                $redis->lTrim($durKey, 0, 1999);
                $redis->expire($durKey, 3024000);

                // 统计 Topic 命中
                if (!empty($topicsHit)) {
                    $topicKey = self::METRICS_PREFIX . 'topics:' . $dateKey;
                    foreach ($topicsHit as $tp) {
                        if ($tp !== '') {
                            $redis->hIncrBy($topicKey, $tp, 1);
                        }
                    }
                    $redis->expire($topicKey, 3024000);
                }
            } catch (\Throwable $e) {
                \App\Syslog::error('AiMetrics', 'Redis record failed: ' . $e->getMessage());
            }
        }

        // 文件持久化（确保无 Redis 环境可用且作为持久备份）
        self::recordToFile($date, $success, $durationMs, $inTokens, $outTokens, $ragCalls, $topicsHit);
    }

    /**
     * 读取运营指标大盘。
     *
     * @param int $days 查询天数（1-30，默认 7）
     * @return array<string, mixed>
     */
    public static function getMetrics(int $days = 7): array
    {
        $days = max(1, min(30, $days));
        $fileData = self::readFileMetrics();
        $history = $fileData['history'] ?? [];

        $redis = RedisClient::getRedis();
        $dates = [];
        $now = time();
        for ($i = $days - 1; $i >= 0; $i--) {
            $dates[] = date('Y-m-d', $now - ($i * 86400));
        }

        $totalRequests = 0;
        $successRequests = 0;
        $failedRequests = 0;
        $totalDurationMs = 0;
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $totalRagCalls = 0;
        $allDurations = [];
        $topicAgg = [];
        $trends = [];

        $dist = [
            'fast' => 0,    // < 2s
            'normal' => 0,  // 2s - 5s
            'slow' => 0,    // 5s - 10s
            'verySlow' => 0 // > 10s
        ];

        foreach ($dates as $d) {
            $dateKey = str_replace('-', '', $d);
            $dayData = $history[$d] ?? [];
            $req = (int) ($dayData['total_requests'] ?? 0);
            $succ = (int) ($dayData['success_requests'] ?? 0);
            $fail = (int) ($dayData['failed_requests'] ?? 0);
            $durSum = (int) ($dayData['total_duration_ms'] ?? 0);
            $inTok = (int) ($dayData['input_tokens'] ?? 0);
            $outTok = (int) ($dayData['output_tokens'] ?? 0);
            $rag = (int) ($dayData['rag_calls'] ?? 0);
            $durList = is_array($dayData['durations'] ?? null) ? $dayData['durations'] : [];

            // 优先用 Redis 当天即时数据覆盖
            if ($redis !== null) {
                try {
                    $hash = $redis->hGetAll(self::METRICS_PREFIX . 'summary:' . $dateKey);
                    if (is_array($hash) && !empty($hash)) {
                        $req = (int) ($hash['total_requests'] ?? 0);
                        $succ = (int) ($hash['success_requests'] ?? 0);
                        $fail = (int) ($hash['failed_requests'] ?? 0);
                        $durSum = (int) ($hash['total_duration_ms'] ?? 0);
                        $inTok = (int) ($hash['input_tokens'] ?? 0);
                        $outTok = (int) ($hash['output_tokens'] ?? 0);
                        $rag = (int) ($hash['rag_calls'] ?? 0);
                    }
                    $rDur = $redis->lRange(self::METRICS_PREFIX . 'durations:' . $dateKey, 0, 999);
                    if (is_array($rDur) && !empty($rDur)) {
                        $durList = array_map('intval', $rDur);
                    }
                    $rTopics = $redis->hGetAll(self::METRICS_PREFIX . 'topics:' . $dateKey);
                    if (is_array($rTopics)) {
                        foreach ($rTopics as $tp => $c) {
                            $topicAgg[$tp] = ($topicAgg[$tp] ?? 0) + (int) $c;
                        }
                    }
                } catch (\Throwable) {
                }
            }

            // 合并文件中的 topics
            if ($redis === null && is_array($dayData['topics'] ?? null)) {
                foreach ($dayData['topics'] as $tp => $c) {
                    $topicAgg[$tp] = ($topicAgg[$tp] ?? 0) + (int) $c;
                }
            }

            $totalRequests += $req;
            $successRequests += $succ;
            $failedRequests += $fail;
            $totalDurationMs += $durSum;
            $totalInputTokens += $inTok;
            $totalOutputTokens += $outTok;
            $totalRagCalls += $rag;

            foreach ($durList as $val) {
                $allDurations[] = (int) $val;
                if ($val < 2000) {
                    $dist['fast']++;
                } elseif ($val < 5000) {
                    $dist['normal']++;
                } elseif ($val < 10000) {
                    $dist['slow']++;
                } else {
                    $dist['verySlow']++;
                }
            }

            $avgDur = $req > 0 ? (int) round($durSum / $req) : 0;
            $trends[] = [
                'date' => $d,
                'requests' => $req,
                'success' => $succ,
                'failed' => $fail,
                'avgDurationMs' => $avgDur,
                'tokens' => $inTok + $outTok,
            ];
        }

        // 计算分位数 (P50, P90, P99)
        sort($allDurations);
        $countDur = count($allDurations);
        $p50 = 0;
        $p90 = 0;
        $p99 = 0;
        if ($countDur > 0) {
            $p50 = $allDurations[(int) floor($countDur * 0.5)];
            $p90 = $allDurations[(int) min($countDur - 1, floor($countDur * 0.9))];
            $p99 = $allDurations[(int) min($countDur - 1, floor($countDur * 0.99))];
        }

        // 格式化 Topic 分布
        arsort($topicAgg);
        $topicList = [];
        $totalTopicHits = array_sum($topicAgg);
        foreach ($topicAgg as $tp => $c) {
            $topicList[] = [
                'topic' => (string) $tp,
                'count' => (int) $c,
                'percentage' => $totalTopicHits > 0 ? round(($c / $totalTopicHits) * 100, 1) : 0.0,
            ];
        }

        $successRate = $totalRequests > 0 ? round(($successRequests / $totalRequests) * 100, 1) : 100.0;
        $avgDuration = $totalRequests > 0 ? (int) round($totalDurationMs / $totalRequests) : 0;

        return [
            'summary' => [
                'totalRequests' => $totalRequests,
                'successRequests' => $successRequests,
                'failedRequests' => $failedRequests,
                'successRate' => $successRate,
                'avgDurationMs' => $avgDuration,
                'p50DurationMs' => $p50,
                'p90DurationMs' => $p90,
                'p99DurationMs' => $p99,
                'estInputTokens' => $totalInputTokens,
                'estOutputTokens' => $totalOutputTokens,
                'estTotalTokens' => $totalInputTokens + $totalOutputTokens,
                'ragCalls' => $totalRagCalls,
            ],
            'trends' => $trends,
            'topics' => array_slice($topicList, 0, 15),
            'durationDistribution' => [
                ['label' => '< 2s (极速)', 'count' => $dist['fast']],
                ['label' => '2s - 5s (正常)', 'count' => $dist['normal']],
                ['label' => '5s - 10s (较长)', 'count' => $dist['slow']],
                ['label' => '> 10s (深层推理)', 'count' => $dist['verySlow']],
            ],
        ];
    }

    /**
     * 判断当前微队列是否处于暂停消费状态。
     */
    public static function isPaused(): bool
    {
        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                if ($redis->get(self::PAUSE_KEY) === '1') {
                    return true;
                }
            } catch (\Throwable) {
            }
        }

        $file = CORE_PATH . self::PAUSE_FILE;
        return file_exists($file);
    }

    /**
     * 设置或解除微队列暂停状态。
     */
    public static function setPaused(bool $paused): void
    {
        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                if ($paused) {
                    $redis->set(self::PAUSE_KEY, '1');
                } else {
                    $redis->del(self::PAUSE_KEY);
                }
            } catch (\Throwable $e) {
                \App\Syslog::error('AiMetrics', 'Set pause key failed: ' . $e->getMessage());
            }
        }

        $file = CORE_PATH . self::PAUSE_FILE;
        if ($paused) {
            file_put_contents($file, json_encode(['pausedAt' => time(), 'operator' => 'admin']));
        } else {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        \App\Syslog::info('AiMetrics', $paused ? 'AI 微队列已由管理员手动暂停消费' : 'AI 微队列已由管理员恢复消费');
    }

    /**
     * 深层探查当前队列状态与任务积压。
     *
     * @param int $limit 探查任务数上限
     * @return array<string, mixed>
     */
    public static function inspectQueue(int $limit = 20): array
    {
        $cfg = AnalysisQueue::config();
        $isPaused = self::isPaused();
        $queueDepth = 0;
        $consumers = [];
        $pendingJobs = [];

        try {
            $queueDepth = AnalysisQueue::queueDepth();
        } catch (\Throwable) {
        }

        // 探查活跃消费者与在途任务
        try {
            $rawConsumers = RedisStreams::xInfoConsumers(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP);
            foreach ($rawConsumers as $c) {
                $consumers[] = [
                    'name' => (string) ($c['name'] ?? ''),
                    'pending' => (int) ($c['pending'] ?? 0),
                    'idleMs' => (int) ($c['idle'] ?? 0),
                ];
            }
        } catch (\Throwable) {
        }

        // 读取 Stream 中尚未完成的待处理任务
        try {
            // 通过 xReadGroup 或 XPENDING 获取积压详情
            $pendingEntries = RedisStreams::xPending(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP, '-', '+', min(50, $limit));
            $now = time();
            foreach ($pendingEntries as $entry) {
                $entryId = (string) ($entry[0] ?? '');
                $consumer = (string) ($entry[1] ?? '');
                $idleMs = (int) ($entry[2] ?? 0);
                $deliveries = (int) ($entry[3] ?? 1);

                // 解析时间戳 (Redis Stream ID 前半部分是毫秒戳)
                $tsMs = (int) explode('-', $entryId)[0];
                $ageSeconds = $tsMs > 0 ? max(0, $now - (int) ($tsMs / 1000)) : 0;

                $pendingJobs[] = [
                    'entryId' => $entryId,
                    'consumer' => $consumer,
                    'idleMs' => $idleMs,
                    'deliveries' => $deliveries,
                    'ageSeconds' => $ageSeconds,
                ];
            }
        } catch (\Throwable) {
        }

        $deadJobs = self::getDeadJobs(15);

        return [
            'isPaused' => $isPaused,
            'enabled' => (bool) $cfg['enabled'],
            'depth' => $queueDepth,
            'maxQueue' => (int) $cfg['maxQueue'],
            'maxConcurrent' => (int) $cfg['maxConcurrent'],
            'consumers' => $consumers,
            'pendingJobs' => $pendingJobs,
            'deadJobs' => $deadJobs,
        ];
    }

    /**
     * 一键安全清空队列中的积压任务。
     *
     * @return array{cleared: int}
     */
    public static function flushQueue(): array
    {
        $cleared = 0;
        try {
            $pendingEntries = RedisStreams::xPending(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP, '-', '+', 1000);
            foreach ($pendingEntries as $entry) {
                $entryId = (string) ($entry[0] ?? '');
                if ($entryId !== '') {
                    RedisStreams::xAck(AnalysisQueue::QUEUE_KEY, AnalysisQueue::GROUP, $entryId);
                    RedisStreams::xDel(AnalysisQueue::QUEUE_KEY, $entryId);
                    $cleared++;
                }
            }
        } catch (\Throwable $e) {
            \App\Syslog::error('AiMetrics', 'flushQueue error: ' . $e->getMessage());
        }

        \App\Syslog::warning('AiMetrics', sprintf('管理员执行了队列清空操作，共清理 %d 个待处理任务', $cleared));
        return ['cleared' => $cleared];
    }

    /**
     * 记录死信任务。
     *
     * @param string $jobId
     * @param string $reason 失败原因
     * @param array<string, mixed>|null $details 额外上下文
     */
    public static function recordDeadJob(string $jobId, string $reason, ?array $details = null): void
    {
        $record = [
            'jobId' => $jobId,
            'reason' => $reason,
            'time' => time(),
            'details' => $details ?? [],
        ];

        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                $redis->lPush(self::DEAD_KEY, json_encode($record, JSON_UNESCAPED_UNICODE));
                $redis->lTrim(self::DEAD_KEY, 0, 99);
            } catch (\Throwable) {
            }
        }

        // 文件兜底
        $file = CORE_PATH . self::DEAD_FILE;
        $list = [];
        if (file_exists($file)) {
            $c = @file_get_contents($file);
            $dec = json_decode((string) $c, true);
            if (is_array($dec)) {
                $list = $dec;
            }
        }
        array_unshift($list, $record);
        if (count($list) > 100) {
            $list = array_slice($list, 0, 100);
        }
        @file_put_contents($file, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取死信列表。
     *
     * @param int $limit
     * @return array<int, array{jobId: string, reason: string, time: int, details: array<string, mixed>}>
     */
    public static function getDeadJobs(int $limit = 20): array
    {
        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                $raw = $redis->lRange(self::DEAD_KEY, 0, $limit - 1);
                if (is_array($raw) && !empty($raw)) {
                    $result = [];
                    foreach ($raw as $item) {
                        $dec = json_decode((string) $item, true);
                        if (is_array($dec) && isset($dec['jobId'])) {
                            $result[] = $dec;
                        }
                    }
                    return $result;
                }
            } catch (\Throwable) {
            }
        }

        $file = CORE_PATH . self::DEAD_FILE;
        if (file_exists($file)) {
            $c = @file_get_contents($file);
            $dec = json_decode((string) $c, true);
            if (is_array($dec)) {
                return array_slice($dec, 0, $limit);
            }
        }
        return [];
    }

    /**
     * 清空死信记录。
     */
    public static function clearDeadJobs(): int
    {
        $count = 0;
        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                $count = (int) $redis->lLen(self::DEAD_KEY);
            } catch (\Throwable) {
            }
            try {
                $redis->del(self::DEAD_KEY);
            } catch (\Throwable) {
            }
        }

        $file = CORE_PATH . self::DEAD_FILE;
        if (file_exists($file)) {
            if ($count === 0) {
                $c = @file_get_contents($file);
                $dec = json_decode((string) $c, true);
                if (is_array($dec)) {
                    $count = count($dec);
                }
            }
            @unlink($file);
        }

        return $count;
    }

    /**
     * 将单次统计写入本地 JSON 文件。
     *
     * @param array<int, string> $topicsHit
     */
    private static function recordToFile(
        string $date,
        bool $success,
        int $durationMs,
        int $inTokens,
        int $outTokens,
        int $ragCalls,
        array $topicsHit
    ): void {
        $file = CORE_PATH . self::METRICS_FILE;
        $data = self::readFileMetrics();
        if (!isset($data['history'][$date])) {
            $data['history'][$date] = [
                'total_requests' => 0,
                'success_requests' => 0,
                'failed_requests' => 0,
                'total_duration_ms' => 0,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'rag_calls' => 0,
                'durations' => [],
                'topics' => [],
            ];
        }

        $day = &$data['history'][$date];
        $day['total_requests']++;
        if ($success) {
            $day['success_requests']++;
        } else {
            $day['failed_requests']++;
        }
        $day['total_duration_ms'] += max(0, $durationMs);
        $day['input_tokens'] += $inTokens;
        $day['output_tokens'] += $outTokens;
        $day['rag_calls'] += $ragCalls;
        $day['durations'][] = $durationMs;
        if (count($day['durations']) > 200) {
            $day['durations'] = array_slice($day['durations'], -200);
        }

        foreach ($topicsHit as $tp) {
            if ($tp !== '') {
                $day['topics'][$tp] = ($day['topics'][$tp] ?? 0) + 1;
            }
        }

        // 仅保留近 35 天
        if (count($data['history']) > 35) {
            ksort($data['history']);
            $data['history'] = array_slice($data['history'], -35, null, true);
        }

        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 读取本地持久化 metrics 文件。
     *
     * @return array<string, mixed>
     */
    private static function readFileMetrics(): array
    {
        $file = CORE_PATH . self::METRICS_FILE;
        if (!file_exists($file)) {
            return ['history' => []];
        }
        $c = @file_get_contents($file);
        if ($c === false || $c === '') {
            return ['history' => []];
        }
        $dec = json_decode($c, true);
        return is_array($dec) ? $dec : ['history' => []];
    }
}
