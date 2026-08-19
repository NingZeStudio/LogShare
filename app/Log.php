<?php

namespace App;

use Aternos\Codex\Analysis\Analysis;
use Aternos\Codex\Analysis\Information;
use Aternos\Codex\Log\File\StringLogFile;
use Aternos\Codex\Minecraft\Analysis\Information\Vanilla\VanillaVersionInformation;
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
    protected \Aternos\Codex\Log\Log $log;

    /**
     * @var Analysis
     */
    protected Analysis $analysis;

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
                if ($result !== null) {
                    error_log("[Redis] 缓存命中: " . $this->id->getRaw());
                }
            } catch (\Exception $e) {
                error_log("[Redis] 缓存读取失败: " . $e->getMessage());
            }
        }

        if ($result === null) {
            error_log("[Redis] 缓存未命中，回退到 MariaDB: " . $this->id->getRaw());

            /**
             * @var StorageInterface $storage
             */
            $storage = $config['storages'][$this->id->getStorage()]['class'];
            $result = $storage::Get($this->id);

            if ($result !== null && $this->isCacheEnabled()) {
                if ($this->shouldCacheToRedis($result['data'])) {
                    try {
                        $this->saveToRedisCache($result);
                    } catch (\Exception $e) {
                        error_log("[Redis] 缓存写入失败: " . $e->getMessage());
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

        $this->analyse();
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
        $this->deobfuscateContent();
        return $this->analysis;
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
     * deobfuscate the content of this log via the SpinYarn PHP extension
     * @return void
     */
    protected function deobfuscateContent()
    {
        $mappingType = $this->getMappingType();
        if ($mappingType === null) {
            return;
        }

        /**
         * @var ?Information $version
         */
        $version = $this->analysis->getFilteredInsights(VanillaVersionInformation::class)[0] ?? null;
        if (!$version) {
            return;
        }
        $version = $version->getValue();

        $content = \App\Client\SpinYarnClient::deobfuscate($this->data, $version, $mappingType);
        if ($content === null) {
            return;
        }

        $this->data = $content;
        $detected = (new Detective())->setLogFile(new StringLogFile($this->data))->detect();
        /** @var \Aternos\Codex\Log\Log $detected */
        $this->log = $detected;
        $this->log->parse();
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
        $this->token = $token ?? new Token();
        $this->metadata = $metadata;
        $this->source = $source;
        $this->files = [];

        if (!empty($files)) {
            $filteredFiles = [];
            foreach ($files as $file) {
                $name = $file['name'] ?? '';
                $content = $file['data'] ?? '';
                $filteredContent = $content;
                $filterConfig = Config::Get('filter');
                foreach ($filterConfig['pre'] as $preFilterClass) {
                    $filteredContent = $preFilterClass::filter($filteredContent);
                }
                $filteredFiles[] = [
                    'name' => $name,
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
            $filesBytes = array_sum(array_map(fn($file) => strlen($file['data'] ?? ''), $this->files));
            $cacheConfig = Config::Get('cache');
            $maxCacheSize = $cacheConfig['maxSize'] ?? (600 * 1024);
            if ($this->shouldCacheToRedis($this->data) && strlen($this->data) + $filesBytes <= $maxCacheSize) {
                try {
                    $this->saveToRedisCache([
                        'data' => $this->data,
                        'token' => $this->token->get(),
                        'metadata' => array_map(fn($entry) => $entry->jsonSerialize(), $this->metadata),
                        'source' => $this->source,
                        'created' => time(),
                        'files' => $this->files,
                    ]);
                    error_log("[Redis] 缓存写入成功: " . $this->id->getRaw());
                } catch (\Exception $e) {
                    error_log("[Redis] 缓存写入失败: " . $e->getMessage() . "，已降级到 MariaDB");
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
        $config = Config::Get('storage');

        /**
         * @var StorageInterface $storage
         */
        $storage = $config['storages'][$this->id->getStorage()]['class'];

        $storage::Renew($this->id);

        if ($this->id->getStorage() === 'f' && mt_rand(1, 100) === 1) {
            try {
                \App\Storage\FilesystemStorage::CleanupExpired();
            } catch (\Exception $e) {
                error_log("[Filesystem] 过期日志清理失败: " . $e->getMessage());
            }
        }

        if ($this->isCacheEnabled()) {
            try {
                $this->renewRedisCache();
                error_log("[Redis] 缓存 TTL 续期成功: " . $this->id->getRaw());
            } catch (\Exception $e) {
                error_log("[Redis] 缓存 TTL 续期失败: " . $e->getMessage());
            }
        }

        $this->expires = time() + $config['storageTime'];
    }

    /**
     * Apply pre filters to data string
     */
    private function preFilter()
    {
        $config = Config::Get('filter');
        foreach ($config['pre'] as $preFilterClass) {
            $this->data = $preFilterClass::filter($this->data);
        }
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
                error_log("[Redis] 缓存删除成功: " . $this->id->getRaw());
            } catch (\Exception $e) {
                error_log("[Redis] 缓存删除失败: " . $e->getMessage());
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
            error_log("[Redis] 缓存数据 JSON 解析失败: " . $cacheKey);
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
     * 判断日志是否应该缓存到 Redis
     *
     * @param string $data 日志数据
     * @return bool
     */
    private function shouldCacheToRedis(string $data): bool
    {
        $config = Config::Get('cache');
        $maxCacheSize = $config['maxSize'] ?? (600 * 1024);
        return strlen($data) <= $maxCacheSize;
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
