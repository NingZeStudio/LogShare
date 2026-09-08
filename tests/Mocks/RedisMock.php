<?php

namespace Tests\Mocks;

class RedisMock
{
    private static array $data = [];
    private static array $ttl = [];

    /** Streams 模拟：key => [['id' => string, 'fields' => array]]（插入序） */
    private static array $streams = [];
    private static int $streamSeq = 0;
    /** key|group => ['delivered' => int, 'pending' => [id => ['consumer' => string, 'at' => float]]] */
    private static array $groups = [];
    /** 故障注入：true 时所有命令抛异常（fail-open 路径测试用） */
    public static bool $failAll = false;

    private static function guard(): void
    {
        if (self::$failAll) {
            throw new \RuntimeException('RedisMock: simulated failure');
        }
    }

    public function __construct() {}

    public function connect(): bool { return true; }
    public function auth(): bool { return true; }
    public function select(): bool { return true; }
    public function ping(): bool { return true; }
    public function isConnected(): bool { return true; }

    public function get(string $key): string|false
    {
        self::guard();
        if (!isset(self::$data[$key])) {
            return false;
        }
        if (isset(self::$ttl[$key]) && self::$ttl[$key] < time()) {
            unset(self::$data[$key], self::$ttl[$key]);
            return false;
        }
        return self::$data[$key];
    }

    public function exists(string $key): bool
    {
        self::guard();
        if (!isset(self::$data[$key])) {
            return false;
        }
        if (isset(self::$ttl[$key]) && self::$ttl[$key] < time()) {
            unset(self::$data[$key], self::$ttl[$key]);
            return false;
        }
        return true;
    }

    public function set(string $key, string $value, string|array|int $mode = 'EX', int $ttl = 0): bool
    {
        self::guard();
        // 支持 ext-redis 的选项数组形式：set($key, $value, ['nx', 'ex' => 10])
        if (is_array($mode)) {
            $ttl = (int) ($mode['ex'] ?? 0);
            if (in_array('nx', $mode, true) && isset(self::$data[$key])) {
                return false;
            }
        }
        self::$data[$key] = $value;
        if ($ttl > 0) {
            self::$ttl[$key] = time() + $ttl;
        }
        return true;
    }

    public function setEx(string $key, int $ttl, string $value): bool
    {
        self::guard();
        self::$data[$key] = $value;
        if ($ttl > 0) {
            self::$ttl[$key] = time() + $ttl;
        }
        return true;
    }

    public function del(string ...$keys): int
    {
        self::guard();
        $count = 0;
        foreach ($keys as $key) {
            if (isset(self::$data[$key])) {
                unset(self::$data[$key], self::$ttl[$key]);
                $count++;
            }
        }
        return $count;
    }

    public function incr(string $key): int
    {
        self::guard();
        $val = (int)(self::$data[$key] ?? 0) + 1;
        self::$data[$key] = (string)$val;
        return $val;
    }

    public function expire(string $key, int $ttl): bool
    {
        self::guard();
        if (isset(self::$data[$key])) {
            self::$ttl[$key] = time() + $ttl;
            return true;
        }
        return false;
    }

    public function ttl(string $key): int
    {
        if (!isset(self::$ttl[$key])) {
            return -2;
        }
        $remaining = self::$ttl[$key] - time();
        return $remaining > 0 ? $remaining : -2;
    }

    public function keys(string $pattern): array
    {
        $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/';
        return array_filter(array_keys(self::$data), fn($k) => preg_match($regex, $k));
    }

    /* ─── Streams（AI 微队列测试用，语义按最小实现） ─── */

    public function xadd(string $key, string $id, array $values, ?int $maxlen = 0, bool $approx = false, bool $nomkstream = false): string
    {
        self::guard();
        $fields = $values;
        $newId = ($id !== null && $id !== '*') ? $id : (++self::$streamSeq) . '-1';
        self::$streams[$key][] = ['id' => $newId, 'fields' => $fields];
        if ($maxlen > 0 && count(self::$streams[$key]) > $maxlen) {
            self::$streams[$key] = array_slice(self::$streams[$key], -$maxlen);
        }
        return $newId;
    }

    public function xlen(string $key): int
    {
        self::guard();
        return count(self::$streams[$key] ?? []);
    }

    public function xgroup(string $operation, string $key, string $group, $id = null, $mkstream = false): bool
    {
        self::guard();
        if (strtoupper($operation) !== 'CREATE') {
            throw new \RuntimeException('RedisMock: only CREATE is supported');
        }
        $gk = $key . '|' . $group;
        if (isset(self::$groups[$gk])) {
            throw new \RuntimeException('BUSYGROUP Consumer Group name already exists');
        }
        self::$groups[$gk] = ['delivered' => 0, 'pending' => []];
        return true;
    }

    public function xreadgroup(string $group, string $consumer, array $keys, int $count = 1, int $block = 0): array
    {
        self::guard();
        $out = [];
        foreach ($keys as $key => $from) {
            if ($from !== '>') {
                continue; // mock 只支持新消息读取
            }
            $gk = $key . '|' . $group;
            if (!isset(self::$groups[$gk])) {
                throw new \RuntimeException("NOGROUP No such consumer group '{$group}' for key name '{$key}'");
            }
            $entries = [];
            $g = &self::$groups[$gk];
            while ($g['delivered'] < count(self::$streams[$key] ?? []) && count($entries) < $count) {
                $entry = self::$streams[$key][$g['delivered']];
                $g['delivered']++;
                $g['pending'][$entry['id']] = ['consumer' => $consumer, 'at' => microtime(true)];
                $entries[$entry['id']] = $entry['fields'];
            }
            unset($g);
            if ($entries !== []) {
                // 与 phpredis 一致：[streamKey => [entryId => fields]]
                $out[$key] = $entries;
            }
        }
        return $out;
    }

    public function xread(array $keys, int $count = 1, int $block = 0): array
    {
        self::guard();
        $out = [];
        foreach ($keys as $key => $fromId) {
            $fromSeq = self::idSeq($fromId);
            $entries = [];
            foreach (self::$streams[$key] ?? [] as $entry) {
                if (self::idSeq($entry['id']) > $fromSeq) {
                    $entries[$entry['id']] = $entry['fields'];
                    if (count($entries) >= $count) {
                        break;
                    }
                }
            }
            if ($entries !== []) {
                $out[$key] = $entries;
            }
        }
        return $out;
    }

    public function xack(string $key, string $group, array $ids): int
    {
        self::guard();
        $gk = $key . '|' . $group;
        $n = 0;
        foreach ($ids as $id) {
            if (isset(self::$groups[$gk]['pending'][$id])) {
                unset(self::$groups[$gk]['pending'][$id]);
                $n++;
            }
        }
        return $n;
    }

    public function xautoclaim(string $key, string $group, string $consumer, int $minIdleMs, string $start = '0-0', int $count = 100): array
    {
        self::guard();
        $gk = $key . '|' . $group;
        $claimed = [];
        $g = &self::$groups[$gk];
        foreach ($g['pending'] ?? [] as $id => $info) {
            if (count($claimed) >= $count) {
                break;
            }
            if (self::idSeq($id) <= self::idSeq($start)) {
                continue;
            }
            if ((microtime(true) - $info['at']) * 1000 >= $minIdleMs) {
                $g['pending'][$id]['consumer'] = $consumer;
                $g['pending'][$id]['at'] = microtime(true);
                foreach (self::$streams[$key] ?? [] as $entry) {
                    if ($entry['id'] === $id) {
                        $claimed[$id] = $entry['fields'];
                        break;
                    }
                }
            }
        }
        unset($g);
        return ['0-0', $claimed, []];
    }

    private static function idSeq(string $id): int
    {
        return (int) explode('-', $id)[0];
    }

    /** 测试辅助：直接读取事件流全部条目 [id, fields] */
    public static function peekStream(string $key): array
    {
        return array_map(fn($e) => [$e['id'], $e['fields']], self::$streams[$key] ?? []);
    }

    public static function reset(): void
    {
        self::$data = [];
        self::$ttl = [];
        self::$streams = [];
        self::$groups = [];
        self::$failAll = false;
    }
}