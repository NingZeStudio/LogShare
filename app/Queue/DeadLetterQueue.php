<?php

declare(strict_types=1);

namespace App\Queue;

use App\Client\RedisClient;
use App\Client\RedisStreams;

/**
 * 统一事件队列死信管理（Dead Letter Queue）。
 *
 * 记录处理重试耗尽或致命异常的事件条目，支持运维检查、重放与清理。
 */
class DeadLetterQueue
{
    public const DEAD_STREAM = 'events:log:dead';

    /**
     * 将失败事件录入死信队列。
     */
    public static function push(QueueEvent $event, string $reason): ?string
    {
        if (RedisClient::getRedis() === null) {
            \App\Syslog::error('DeadLetterQueue', "Redis unavailable, dead event dropped: {$event->getName()} [{$event->getId()}], reason: {$reason}");
            return null;
        }

        try {
            $data = [
                'id' => $event->getId(),
                'event' => $event->getName(),
                'payload' => json_encode($event->getPayload(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
                'attempts' => (string) $event->getAttempts(),
                'error' => $reason,
                'failedAt' => (string) microtime(true),
            ];

            // 约保留最近 2000 条死信条目
            return RedisStreams::xAdd(self::DEAD_STREAM, $data, 2000);
        } catch (\Throwable $e) {
            \App\Syslog::error('DeadLetterQueue', "Failed to push dead letter: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 获取死信条目总数。
     */
    public static function count(): int
    {
        if (RedisClient::getRedis() === null) {
            return 0;
        }

        try {
            return RedisStreams::xLen(self::DEAD_STREAM);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 分页查询死信条目列表。
     *
     * @return array<int, array{id: string, streamId: string, event: string, payload: array<string, mixed>, attempts: int, error: string, failedAt: float}>
     */
    public static function list(int $limit = 50): array
    {
        $redis = RedisClient::getRedis();
        if ($redis === null) {
            return [];
        }

        try {
            // 使用 xRevRange 从最新往最旧读取
            /** @var array<string, array<string, string>>|false $reply */
            $reply = $redis->xRevRange(self::DEAD_STREAM, '+', '-', $limit);
            if (!is_array($reply)) {
                return [];
            }

            $items = [];
            foreach ($reply as $streamId => $fields) {
                $rawPayload = (string) ($fields['payload'] ?? '{}');
                $payload = json_decode($rawPayload, true);
                $items[] = [
                    'streamId' => (string) $streamId,
                    'id' => (string) ($fields['id'] ?? $streamId),
                    'event' => (string) ($fields['event'] ?? 'unknown'),
                    'payload' => is_array($payload) ? $payload : [],
                    'attempts' => (int) ($fields['attempts'] ?? 1),
                    'error' => (string) ($fields['error'] ?? ''),
                    'failedAt' => (float) ($fields['failedAt'] ?? 0),
                ];
            }
            return $items;
        } catch (\Throwable $e) {
            \App\Syslog::error('DeadLetterQueue', "Failed to list dead letters: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 重放死信事件（重新投递回主队列）。
     */
    public static function retry(string $streamId): bool
    {
        $redis = RedisClient::getRedis();
        if ($redis === null) {
            return false;
        }

        try {
            /** @var array<string, array<string, string>>|false $reply */
            $reply = $redis->xRange(self::DEAD_STREAM, $streamId, $streamId, 1);
            if (!is_array($reply) || empty($reply[$streamId])) {
                return false;
            }

            $fields = $reply[$streamId];
            $event = (string) ($fields['event'] ?? '');
            $rawPayload = (string) ($fields['payload'] ?? '{}');
            $payload = json_decode($rawPayload, true);
            if (!is_array($payload) || $event === '') {
                return false;
            }

            // 重置 attempts 为 1，派发回主队列
            $dispatched = EventQueue::dispatch($event, $payload);
            if ($dispatched) {
                // 从死信流中清除
                RedisStreams::xDel(self::DEAD_STREAM, $streamId);
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            \App\Syslog::error('DeadLetterQueue', "Failed to retry dead letter {$streamId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 清空死信队列。
     */
    public static function clear(): bool
    {
        $redis = RedisClient::getRedis();
        if ($redis === null) {
            return false;
        }

        try {
            $redis->del(self::DEAD_STREAM);
            return true;
        } catch (\Throwable $e) {
            \App\Syslog::error('DeadLetterQueue', "Failed to clear dead letters: " . $e->getMessage());
            return false;
        }
    }
}
