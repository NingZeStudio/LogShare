<?php

declare(strict_types=1);

namespace App\Client;

/**
 * Redis Streams 命令面封装（AI 分析微队列专用）。
 *
 * 复用 RedisClient 的协程级连接隔离；与 RedisCache 不同，本类的键形态是
 * Stream 而非普通缓存，不实现 CacheInterface（架构测试约束 app/Cache 下
 * 的类必须实现该接口，故置于 app/Client）。
 */
class RedisStreams extends RedisClient
{
    /**
     * XADD：'*' 由 Redis 生成 id；maxLen > 0 时按 MAXLEN ~ 近似裁剪。
     *
     * @return string 新条目 id
     */
    public static function xAdd(string $key, array $fields, int $maxLen = 0): string
    {
        $conn = self::connection();
        if ($maxLen > 0) {
            return (string) $conn->xadd($key, '*', $fields, $maxLen, true);
        }
        return (string) $conn->xadd($key, '*', $fields);
    }

    /**
     * XGROUP CREATE ... MKSTREAM；组已存在（BUSYGROUP）视为成功。
     *
     * 捕获 \Throwable 而非 \RedisException：测试环境以 RedisMock 别名 \Redis，
     * ext-redis 缺席时 RedisException 类根本不存在。
     */
    public static function xGroupCreate(string $key, string $group): void
    {
        try {
            self::connection()->xgroup('CREATE', $key, $group, '0', true);
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }

    /**
     * XREADGROUP 读取新消息（'>'）。
     *
     * @return array<int, array{0: string, 1: array}> [id, fields] 列表
     */
    public static function xReadGroup(string $group, string $consumer, string $key, int $blockMs, int $count = 1): array
    {
        $reply = self::connection()->xreadgroup($group, $consumer, [$key => '>'], $count, $blockMs);
        return self::flattenStreamReply($reply, $key);
    }

    /**
     * XACK 确认条目。
     */
    public static function xAck(string $key, string $group, string $id): int
    {
        return (int) self::connection()->xack($key, $group, [$id]);
    }

    /**
     * XLEN：队列深度（近似排队位置的数据源）。
     */
    public static function xLen(string $key): int
    {
        return (int) self::connection()->xlen($key);
    }

    /**
     * XREAD（非组读）：中继端从 $fromId 之后阻塞读取事件流。
     *
     * @return array<int, array{0: string, 1: array}> [id, fields] 列表
     */
    public static function xRead(string $key, string $fromId, int $blockMs, int $count = 100): array
    {
        $reply = self::connection()->xread([$key => $fromId], $count, $blockMs);
        return self::flattenStreamReply($reply, $key);
    }

    /**
     * XAUTOCLAIM：把空闲超过 $minIdleMs 的 pending 条目改挂到 $consumer 名下。
     *
     * @return array<int, array{0: string, 1: array}> [id, fields] 列表
     */
    public static function xAutoClaim(string $key, string $group, string $consumer, int $minIdleMs, int $count = 10): array
    {
        $reply = self::connection()->xautoclaim($key, $group, $consumer, $minIdleMs, '0-0', $count);
        // phpredis 返回 [next-cursor, messages, deleted-ids]；messages 为 [id => fields]
        $messages = $reply[1] ?? [];
        $out = [];
        foreach ($messages as $id => $fields) {
            if (is_array($fields)) {
                $out[] = [(string) $id, $fields];
            }
        }
        return $out;
    }

    /**
     * 把 phpredis 的流读取回复拉平为 [id, fields] 列表。
     *
     * phpredis 真实格式（见其 tests/RedisTest.php compareStreamIds）：
     *   [streamKey => [entryId => fields, ...]]
     * 外层兼容以 streamKey 为键的 map 与 [[streamKey => ...]] 数字列表两种形态。
     */
    private static function flattenStreamReply(mixed $reply, string $key): array
    {
        if (!is_array($reply)) {
            return [];
        }

        $entries = null;
        if (isset($reply[$key]) && is_array($reply[$key])) {
            $entries = $reply[$key];
        } else {
            foreach ($reply as $chunk) {
                if (is_array($chunk) && isset($chunk[$key]) && is_array($chunk[$key])) {
                    $entries = $chunk[$key];
                    break;
                }
            }
        }
        if ($entries === null) {
            return [];
        }

        $out = [];
        foreach ($entries as $id => $fields) {
            if (is_string($id) && is_array($fields)) {
                $out[] = [$id, $fields];
            }
        }
        return $out;
    }

    /* ─── 队列辅助键的普通命令（透传 RedisClient 的 protected op*） ─── */

    public static function set(string $key, string $value, int $ttl): void
    {
        self::opSet($key, $value, $ttl);
    }

    public static function setNxEx(string $key, string $value, int $ttl): bool
    {
        return (bool) self::connection()->set($key, $value, ['nx', 'ex' => $ttl]);
    }

    public static function get(string $key): ?string
    {
        return self::opGet($key);
    }

    public static function del(string $key): bool
    {
        return self::opDel($key);
    }

    public static function expire(string $key, int $seconds): bool
    {
        return self::opExpire($key, $seconds);
    }
}
