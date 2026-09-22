<?php

declare(strict_types=1);

namespace App\Rag;

use App\Client\RedisClient;

/**
 * 查询向量缓存（query → embedding），Redis + 进程内双层。
 *
 * 相同查询重复出现（Agent 循环内多次检索、用户重试同一问题）时直接命中
 * 向量，零 embedding API 调用。语义与 RagSearch 里既有的「检索结果缓存」
 * 不同：那一层缓存最终排序结果（短 TTL，索引变化敏感）；本层只缓存查询
 * 向量（长 TTL 安全——同一模型对同一文本的向量是稳定的），两者叠加使用。
 *
 * 缓存键包含模型指纹（describe() + query 的 sha256）：切换 embedding 模型
 * 后旧向量维度/语义都不可复用，键不同自然失效。
 *
 * 全部操作 fail-open：Redis 不可用或数据异常时静默回源，绝不影响检索。
 */
final class SemanticCache
{
    private const CACHE_PREFIX = 'rag:embed:query:';
    private const CACHE_TTL = 86400; // 24h

    /** 进程内层：key → vector（FIFO，100 条 / 1MB 上限，见 plan 3.7） */
    private static array $mem = [];
    /** @var array<string, int> 每条目的序列化字节数（增量维护，淘汰 O(1)） */
    private static array $memSizes = [];
    private static int $memBytes = 0;
    private const MEM_MAX_ENTRIES = 100;
    private const MEM_MAX_BYTES = 1048576;

    /** ai.rag.semanticCache 开关（默认开启——只省 API 调用，不改检索结果） */
    public static function enabled(): bool
    {
        try {
            return (\App\Config::Get('ai')['rag']['semanticCache'] ?? true) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** 测试用：清空进程内层 */
    public static function clearMemory(): void
    {
        self::$mem = [];
        self::$memSizes = [];
        self::$memBytes = 0;
    }

    public function __construct(private readonly string $modelFingerprint)
    {
    }

    private function key(string $query): string
    {
        return self::CACHE_PREFIX . hash('sha256', $this->modelFingerprint . '|' . $query);
    }

    /**
     * @return array<int, float>|null
     */
    public function get(string $query): ?array
    {
        if (!self::enabled() || $query === '') {
            return null;
        }
        $key = $this->key($query);

        $vec = self::$mem[$key] ?? null;
        if ($vec !== null) {
            return $vec;
        }

        try {
            $redis = RedisClient::getRedis();
            if ($redis === null) {
                return null;
            }
            $raw = $redis->get($key);
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $decoded = json_decode($raw, true);
            $vec = self::validateVector($decoded);
            if ($vec === null) {
                return null;
            }
            // 回填进程内层（不回写 TTL，Redis 侧剩余 TTL 继续生效）
            self::memPut($key, $vec);
            return $vec;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, float> $vector
     */
    public function set(string $query, array $vector): void
    {
        if (!self::enabled() || $query === '' || $vector === []) {
            return;
        }
        $key = $this->key($query);
        self::memPut($key, $vector);

        try {
            $redis = RedisClient::getRedis();
            if ($redis === null) {
                return;
            }
            $encoded = json_encode(array_map('floatval', $vector));
            if (is_string($encoded)) {
                $redis->setEx($key, self::CACHE_TTL, $encoded);
            }
        } catch (\Throwable) {
            // fail-open：写不进 Redis 只是少一层缓存
        }
    }

    /**
     * @param mixed $decoded
     * @return array<int, float>|null
     */
    private static function validateVector(mixed $decoded): ?array
    {
        if (!is_array($decoded) || $decoded === [] || count($decoded) > 8192) {
            return null;
        }
        foreach ($decoded as $v) {
            if (!is_numeric($v)) {
                return null;
            }
        }
        return array_map('floatval', array_values($decoded));
    }

    /**
     * @param array<int, float> $vector
     */
    private static function memPut(string $key, array $vector): void
    {
        if (isset(self::$mem[$key])) {
            return;
        }
        $bytes = strlen(serialize($vector));
        if ($bytes > self::MEM_MAX_BYTES) {
            return;
        }
        while (self::$mem !== []
            && (count(self::$mem) >= self::MEM_MAX_ENTRIES || self::$memBytes + $bytes > self::MEM_MAX_BYTES)) {
            $evicted = array_key_first(self::$mem);
            self::$memBytes -= self::$memSizes[$evicted] ?? 0;
            unset(self::$mem[$evicted], self::$memSizes[$evicted]);
        }
        self::$mem[$key] = $vector;
        self::$memSizes[$key] = $bytes;
        self::$memBytes += $bytes;
    }
}
