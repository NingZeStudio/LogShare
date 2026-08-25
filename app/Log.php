<?php

namespace App;

use Aternos\Codex\Analysis\Analysis;
use Aternos\Codex\Log\File\StringLogFile;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\Fabric\FabricLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\VanillaClientLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\VanillaCrashReportLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\VanillaNetworkProtocolErrorReportLog;
use Aternos\Codex\Minecraft\Log\Minecraft\Vanilla\VanillaServerLog;
use App\Data\MetadataEntry;
use App\Data\Token;
use App\Filter\Filter;
use App\Storage\StorageInterface;

class Log
{
    private bool $exists = false;
    private ?Id $id = null;
    private ?string $data = null;
    private ?Token $token = null;
    private array $metadata = [];
    private ?string $source = null;
    private ?int $created = null;
    private ?int $expires = null;
    private array $files = [];
    private ?int $lineCount = null;
    protected ?\Aternos\Codex\Log\Log $log = null;

    /**
     * @var Analysis|null
     */
    protected ?Analysis $analysis = null;

    /**
     * Log constructor.
     *
     * @param Id|null $id
     */
    public function __construct(?Id $id = null)
    {
        if ($id) {
            $this->id = $id;
            $this->load();
        }
    }

    /**
     * Load the log from storage (primary storage with optional Redis cache)
     */
    private function load()
    {
        $config = Config::Get('storage');

        if (!isset($config['storages'][$this->id->getStorage()])) {
            $this->exists = false;
            return;
        }

        if (!$config['storages'][$this->id->getStorage()]['enabled']) {
            $this->exists = false;
            return;
        }

        $result = null;

        if ($this->isCacheEnabled()) {
            try {
                $result = $this->loadFromRedis();
            } catch (\Exception $e) {
                \App\Syslog::error("Redis", "缓存读取失败: " . $e->getMessage());
            }
        }

        if ($result === null) {
            /**
             * @var StorageInterface $storage
             */
            $storage = $config['storages'][$this->id->getStorage()]['class'];
            $result = $storage::Get($this->id);

            if ($result !== null && $this->isCacheEnabled()) {
                $filesBytes = array_sum(array_map(
                    fn($file) => isset($file['size']) ? (int) $file['size'] : strlen($file['data'] ?? ''),
                    $result['files'] ?? []
                ));
                if ($this->shouldCacheToRedis((string) $result['data'], $filesBytes)) {
                    try {
                        $this->saveToRedisCache($result);
                    } catch (\Exception $e) {
                        \App\Syslog::error('Redis', '缓存写入失败: ' . $e->getMessage());
                    }
                }
            }
        }

        if ($result === null) {
            $this->exists = false;
            return;
        }

        $this->data = $result['data'];
        $this->token = isset($result['token']) ? new Token($result['token']) : null;
        $this->metadata = MetadataEntry::allFromArray($result['metadata'] ?? []);
        $this->source = $result['source'] ?? null;
        $createdValue = $result['created'] ?? null;
        $this->created = is_numeric($createdValue) ? (int) $createdValue : null;
        $this->expires = $this->created !== null ? $this->created + $config['storageTime'] : null;
        $this->files = $result['files'] ?? [];
        $this->exists = true;
    }

    /**
     * Analyse the log content
     * @return Analysis
     */
    public function analyse(): Analysis
    {
        $detected = (new Detective())->setLogFile(new StringLogFile($this->data))->detect();
        /** @var \Aternos\Codex\Log\Log $detected */
        $this->log = $detected;
        $this->log->parse();
        $this->analysis = $this->log->analyse();
        return $this->analysis;
    }

    /**
     * 分析结果 JSON 缓存（进程级，keyed by 内容 hash）。
     *
     * Codex 的 detect+parse+analyse 对大型日志成本高昂（~2.5s/3.5MB），同一内容的
     * 重复分析（如 GET /v1/insights/{id} 反复访问）应命中缓存，避免重复全量分析。
     *
     * @var array<string, string>
     */
    private static array $analysisJsonCache = [];

    private const MAX_ANALYSIS_JSON_CACHE = 32;

    /**
     * 返回 Codex 分析结果的 JSON 字符串（含进程级缓存）。
     */
    public function getAnalysisJson(): string
    {
        $key = md5((string) $this->data);
        if (isset(self::$analysisJsonCache[$key])) {
            return self::$analysisJsonCache[$key];
        }

        $codexLog = $this->get();
        $codexLog->setIncludeEntries(false);
        $json = json_encode($codexLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 编码失败（如内容含非法 UTF-8）时不得把空串写入缓存，
        // 否则同内容的后续请求会持续命中坏缓存并返回空响应。
        if ($json === false) {
            throw new ApiError(500, 'Failed to encode analysis result: ' . json_last_error_msg());
        }

        if (count(self::$analysisJsonCache) >= self::MAX_ANALYSIS_JSON_CACHE) {
            array_shift(self::$analysisJsonCache);
        }
        self::$analysisJsonCache[$key] = $json;

        return $json;
    }

    /**
     * Resolve the SpinYarn mapping type for this log.
     *
     * @return string|null "yarn" (Fabric) / "vanilla" (Mojang official), or null to skip
     */
    protected function getMappingType(): ?string
    {
        if (in_array(get_class($this->get()), [
            VanillaServerLog::class,
            VanillaClientLog::class,
            VanillaCrashReportLog::class,
            VanillaNetworkProtocolErrorReportLog::class
        ])) {
            return 'vanilla';
        }

        if ($this->get() instanceof FabricLog) {
            return 'yarn';
        }

        return null;
    }

    /**
     * Deobfuscate the log content before it is stored, so the database holds
     * deobfuscated text and reads need no further deobfuscation.
     *
     * Detects the log type and version, then calls the SpinYarn extension.
     * When the extension is absent or the log needs no deobfuscation, the
     * data passes through unchanged.
     *
     * @return void
     */
    protected function deobfuscateForStorage(): void
    {
        $detected = (new Detective())->setLogFile(new StringLogFile($this->data))->detect();
        if (!$detected instanceof \Aternos\Codex\Minecraft\Log\Minecraft\MinecraftLog) {
            return;
        }
        $this->log = $detected;
        $this->log->parse();

        $mappingType = $this->getMappingType();
        if ($mappingType === null) {
            return;
        }

        // Codex 最佳实践：通过 Log::getVersion() 获取版本（内部走 analyse，带缓存）。
        $version = $detected->getVersion();
        if ($version === null) {
            return;
        }

        $content = \App\Client\SpinYarnClient::deobfuscate($this->data, $version, $mappingType);
        if ($content === null) {
            return;
        }

        $this->data = $content;
        $this->lineCount = null;

        // 反混淆后重置 log/analysis，让后续 get()/analyse() 基于新内容重新检测
        $this->log = null;
        $this->analysis = null;
    }

    /**
     * Checks if the log exists
     *
     * @return bool
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Get the log
     *
     * @return \Aternos\Codex\Log\Log
     */
    public function get(): \Aternos\Codex\Log\Log
    {
        if ($this->log === null) {
            $this->analyse();
        }
        return $this->log;
    }

    /**
     * Get the amount of lines in this log
     *
     * @return int
     */
    public function getLineNumbers(): int
    {
        if ($this->lineCount === null) {
            $this->lineCount = substr_count($this->data, "\n") + 1;
        }
        return $this->lineCount;
    }

    /**
     * Get the raw content of the log
     *
     * @return string
     */
    public function getContent(): string
    {
        return $this->data;
    }

    /**
     * Set the data of the log without saving it to storage
     *
     * @param string $data
     * @return Log
     */
    public function setData(string $data): Log
    {
        $this->data = $data;
        $this->lineCount = null;
        $this->preFilter();
        $this->deobfuscateForStorage();
        return $this;
    }

    /**
     * Put data into the log (with Redis cache)
     *
     * @param string $data
     * @param Token|null $token
     * @param MetadataEntry[] $metadata
     * @param string|null $source
     * @param array|null $files Additional files stored under the same id: [['name' => string, 'data' => string]]
     * @return ?Id
     */
    public function put(string $data, ?Token $token = null, array $metadata = [], ?string $source = null, ?array $files = null): ?Id
    {
        $this->data = $data;
        $this->lineCount = null;
        $this->preFilter();
        $this->deobfuscateForStorage();
        $plainToken = $token ?? new Token();
        // 存储层（MariaDB / 文件系统 / Redis 缓存）只落 SHA-256 哈希；
        // 上传响应通过调用方持有的 $token 原对象返回原文。
        $this->token = new Token(hash('sha256', (string) $plainToken->get()));
        $this->metadata = $metadata;
        $this->source = $source;
        $this->files = [];

        if (!empty($files)) {
            $filteredFiles = [];
            foreach ($files as $file) {
                $filteredContent = $this->preFilterValue($file['data'] ?? '');
                $filteredFiles[] = [
                    'name' => $file['name'] ?? '',
                    'data' => $filteredContent,
                    'size' => strlen($filteredContent),
                ];
            }
            $this->files = $filteredFiles;
        }

        $config = Config::Get('storage');

        /**
         * @var StorageInterface $storage
         */
        $storage = $config['storages'][$config['storageId']]['class'];

        $this->id = $storage::Put($this->data, $this->token, $this->metadata, $this->source, $this->files);
        $this->exists = true;

        if ($this->id !== null && $this->isCacheEnabled()) {
            $filesBytes = array_sum(array_map(fn($file) => strlen($file['data']), $this->files));
            if ($this->shouldCacheToRedis($this->data, $filesBytes)) {
                try {
                    $this->saveToRedisCache([
                        'data' => $this->data,
                        'token' => $this->token->get(),
                        'metadata' => array_map(fn($entry) => $entry->jsonSerialize(), $this->metadata),
                        'source' => $this->source,
                        'created' => time(),
                        'files' => $this->files,
                    ]);
                } catch (\Exception $e) {
                    \App\Syslog::error('Redis', '缓存写入失败: ' . $e->getMessage() . '，已降级到 MariaDB');
                }
            }
        }

        return $this->id;
    }

    /**
     * Renew the expiry timestamp to expand the ttl
     */
    public function renew()
    {
        if (!$this->id) {
            return;
        }

        $config = Config::Get('storage');

        /**
         * @var StorageInterface $storage
         */
        $storage = $config['storages'][$this->id->getStorage()]['class'];

        $storage::Renew($this->id);

        if (mt_rand(1, 100) === 1) {
            try {
                if ($this->id->getStorage() === 'f') {
                    \App\Storage\FilesystemStorage::CleanupExpired();
                } else {
                    \App\Storage\MariaDbStorage::CleanupExpired();
                }
            } catch (\Exception $e) {
                \App\Syslog::error("Storage", "过期日志清理失败: " . $e->getMessage());
            }
        }

        if ($this->isCacheEnabled()) {
            try {
                $this->renewRedisCache();
            } catch (\Exception $e) {
                \App\Syslog::error("Redis", "缓存 TTL 续期失败: " . $e->getMessage());
            }
        }

        $this->expires = time() + $config['storageTime'];
    }

    /**
     * Apply pre filters to data string
     */
    private function preFilter()
    {
        $this->data = $this->preFilterValue((string) $this->data);
    }

    /**
     * Run the pre filter chain over a single content string
     * (shared by the primary log body and additional files).
     */
    private function preFilterValue(string $data): string
    {
        $config = Config::Get('filter');
        foreach ($config['pre'] as $preFilterClass) {
            $data = $preFilterClass::filter($data);
        }
        return $data;
    }

    /**
     * Delete the log from storage (both Redis cache and MariaDB)
     *
     * @return bool Success
     */
    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }

        $config = Config::Get('storage');

        if (!isset($config['storages'][$this->id->getStorage()])) {
            return false;
        }

        if (!$config['storages'][$this->id->getStorage()]['enabled']) {
            return false;
        }

        /**
         * @var StorageInterface $storage
         */
        $storage = $config['storages'][$this->id->getStorage()]['class'];

        $result = $storage::Delete($this->id);

        if ($result) {
            $this->exists = false;
            $this->data = null;
        }

        if ($this->isCacheEnabled()) {
            try {
                $this->deleteFromRedisCache();
            } catch (\Exception $e) {
                \App\Syslog::error("Redis", "缓存删除失败: " . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Get the token
     *
     * @return Token|null
     */
    public function getToken(): ?Token
    {
        return $this->token;
    }

    /**
     * Get the metadata
     *
     * @return MetadataEntry[]
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get the source
     *
     * @return string|null
     */
    public function getSource(): ?string
    {
        return $this->source;
    }

    /**
     * Get the list of additional files stored under this log id
     *
     * Each entry: ['name' => string, 'size' => int]
     *
     * @return array<int, array{name: string, size: int}>
     */
    public function getFiles(): array
    {
        return array_map(fn($file) => [
            'name' => $file['name'] ?? '',
            'size' => isset($file['size']) ? (int) $file['size'] : strlen($file['data'] ?? ''),
        ], $this->files);
    }

    /**
     * Get the raw content of an additional file by its name
     *
     * @param string $name
     * @return string|null
     */
    public function getFile(string $name): ?string
    {
        foreach ($this->files as $file) {
            if (($file['name'] ?? '') === $name) {
                return $file['data'] ?? '';
            }
        }
        return null;
    }

    /**
     * Check whether an additional file exists under this log id
     *
     * @param string $name
     * @return bool
     */
    public function hasFile(string $name): bool
    {
        return $this->getFile($name) !== null;
    }

    /**
     * Get the line count of an additional file
     *
     * @param string $name
     * @return int 0 if the file does not exist
     */
    public function getFileLineNumbers(string $name): int
    {
        $content = $this->getFile($name);
        return $content === null ? 0 : substr_count($content, "\n") + 1;
    }

    /**
     * Get the created timestamp
     *
     * @return int|null
     */
    public function getCreated(): ?int
    {
        return $this->created;
    }

    /**
     * Get the expires timestamp
     *
     * @return int|null
     */
    public function getExpires(): ?int
    {
        return $this->expires;
    }

    /**
     * Get the size of the log content
     *
     * @return int
     */
    public function getSize(): int
    {
        return strlen($this->data);
    }

    /**
     * Verify if the provided token matches
     *
     * @param string $token
     * @return bool
     */
    public function verifyToken(string $token): bool
    {
        if (!$this->token) {
            return false;
        }
        return $this->token->matches($token);
    }

    private function getRedisCacheKey(): string
    {
        return "log:" . $this->id->getRaw();
    }

    private function loadFromRedis(): ?array
    {
        $cacheKey = $this->getRedisCacheKey();

        try {
            $cachedData = \App\Cache\RedisCache::Get($cacheKey);
        } catch (\Exception $e) {
            throw new \Exception("Redis Get 操作失败: " . $e->getMessage(), 0, $e);
        }

        if ($cachedData === null) {
            return null;
        }

        $decoded = json_decode($cachedData, true);
        if ($decoded === null || !is_array($decoded)) {
            \App\Syslog::error("Redis", "缓存数据 JSON 解析失败: " . $cacheKey);
            return null;
        }

        return [
            'data' => $decoded['data'] ?? null,
            'token' => $decoded['token'] ?? null,
            'metadata' => $decoded['metadata'] ?? [],
            'source' => $decoded['source'] ?? null,
            'files' => $decoded['files'] ?? [],
            'created' => $decoded['created'] ?? null,
        ];
    }

    /**
     * 判断日志是否应该缓存到 Redis。
     *
     * 统一口径：主内容 + 附加文件字节总量均不得超过 cache.maxSize，
     * load 回源与 put 写入两条路径共用本判定。
     *
     * @param string $data 主日志数据
     * @param int $filesBytes 附加文件字节总量
     * @return bool
     */
    private function shouldCacheToRedis(string $data, int $filesBytes = 0): bool
    {
        $config = Config::Get('cache');
        $maxCacheSize = $config['maxSize'] ?? (600 * 1024);
        return strlen($data) + $filesBytes <= $maxCacheSize;
    }

    /**
     * 检查 Redis 缓存是否启用
     *
     * @return bool
     */
    private function isCacheEnabled(): bool
    {
        $config = Config::Get('cache');
        return $config['enabled'] ?? true;
    }

    /**
     * 保存日志到 Redis 缓存
     *
     * @param array $data
     * @return void
     * @throws \Exception
     */
    private function saveToRedisCache(array $data): void
    {
        $cacheKey = $this->getRedisCacheKey();
        $config = Config::Get('cache');

        $cacheDataArray = $data;

        $cacheData = json_encode($cacheDataArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($cacheData === false) {
            throw new \Exception("Redis 缓存数据 JSON 编码失败: " . json_last_error_msg());
        }

        try {
            \App\Cache\RedisCache::Set($cacheKey, $cacheData, $config['ttl'] ?? 1800);
        } catch (\Exception $e) {
            throw new \Exception("Redis Set 操作失败: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 续期 Redis 缓存 TTL
     *
     * @return void
     * @throws \Exception
     */
    private function renewRedisCache(): void
    {
        $cacheKey = $this->getRedisCacheKey();
        $config = Config::Get('cache');

        try {
            $cacheData = \App\Cache\RedisCache::Get($cacheKey);
        } catch (\Exception $e) {
            throw new \Exception("Redis Get 操作失败: " . $e->getMessage(), 0, $e);
        }

        if ($cacheData !== null) {
            try {
                \App\Cache\RedisCache::Set($cacheKey, $cacheData, $config['ttl'] ?? 1800);
            } catch (\Exception $e) {
                throw new \Exception("Redis Set 操作失败: " . $e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * 从 Redis 缓存删除日志
     *
     * @return void
     * @throws \Exception
     */
    private function deleteFromRedisCache(): void
    {
        $cacheKey = $this->getRedisCacheKey();

        try {
            \App\Cache\RedisCache::Delete($cacheKey);
        } catch (\Exception $e) {
            throw new \Exception("Redis Delete 操作失败: " . $e->getMessage(), 0, $e);
        }
    }

}
