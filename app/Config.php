<?php

namespace App;

class Config
{
    private static array $data = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            // 配置缺失（如 Docker 镜像内无 Config.inc.php）时回退到示例配置，
            // 避免 `require` 直接 fatal 导致进程无法启动。
            $example = CORE_PATH . '/Config.inc.example.php';
            if (is_file($example)) {
                $path = $example;
            } else {
                self::$loaded = true;
                return;
            }
        }

        $data = require $path;
        if (!is_array($data)) {
            // 配置文件损坏/返回类型错误时 fail-fast：常驻进程下静默降级会导致
            // 每次访问配置都重读文件，且业务拿到空配置产生更难排查的次生故障
            throw new \InvalidArgumentException("Config file {$path} must return an array.");
        }
        self::applyEnvironmentOverrides($data);
        // Example 配置中的占位符（如 '${REDIS_PASSWORD}'）在非 Docker 部署下不会
        // 被替换，占位符形态一律视为未配置，避免拿字面量去 AUTH Redis
        if (isset($data['cache']['redis']['password'])
            && is_string($data['cache']['redis']['password'])
            && preg_match('/^\$\{[A-Z_]+\}$/', $data['cache']['redis']['password']) === 1) {
            $data['cache']['redis']['password'] = '';
        }
        self::validate($data);
        self::$data = $data;
        self::$loaded = true;
    }

    /**
     * Allow environment variables to override configuration values.
     *
     * Supported variables:
     *  - REDIS_HOST         → cache.redis.host
     *  - REDIS_PORT         → cache.redis.port
     *  - REDIS_TIMEOUT      → cache.redis.timeout
     *  - REDIS_PASSWORD     → cache.redis.password
     *  - AI_API_KEYS        → ai.apiKeys (comma-separated)
     *  - AI_BASE_URL        → ai.baseUrl
     *  - AI_MODEL           → ai.model
     *  - AI_ENABLED         → ai.enabled (1/true/on/yes = true)
     *  - AI_RAG_ENABLED     → ai.rag.enabled (semantic RAG switch)
     *  - AI_RAG_PROVIDERS   → ai.rag.providers (JSON array)
     *
     * @param array $data Config array by reference
     * @return void
     */
    private static function applyEnvironmentOverrides(array &$data): void
    {
        if (($storageTime = getenv('STORAGE_TIME')) !== false && ctype_digit($storageTime) && (int) $storageTime > 0) {
            $data['storage']['storageTime'] = (int) $storageTime;
        }
        if ($host = getenv('REDIS_HOST')) {
            $data['cache']['redis']['host'] = $host;
        }
        if ($port = getenv('REDIS_PORT')) {
            $data['cache']['redis']['port'] = (int) $port;
        }
        if ($timeout = getenv('REDIS_TIMEOUT')) {
            $data['cache']['redis']['timeout'] = (float) $timeout;
        }
        if (($password = getenv('REDIS_PASSWORD')) !== false && $password !== '') {
            $data['cache']['redis']['password'] = $password;
        }
        if (($enabled = getenv('AI_RAG_ENABLED')) !== false) {
            $data['ai']['rag']['enabled'] = in_array(strtolower($enabled), ['1', 'true', 'on', 'yes'], true);
        }
        if (($providers = getenv('AI_RAG_PROVIDERS')) !== false && $providers !== '') {
            $decoded = json_decode($providers, true);
            if (is_array($decoded)) {
                $data['ai']['rag']['providers'] = $decoded;
            }
        }

        if ($keys = getenv('AI_API_KEYS')) {
            $data['ai']['apiKeys'] = array_values(array_filter(array_map('trim', explode(',', $keys)), fn($k) => $k !== ''));
        }
        if ($baseUrl = getenv('AI_BASE_URL')) {
            $data['ai']['baseUrl'] = $baseUrl;
        }
        if ($model = getenv('AI_MODEL')) {
            $data['ai']['model'] = $model;
        }
        if (($enabled = getenv('AI_ENABLED')) !== false) {
            $data['ai']['enabled'] = in_array(strtolower($enabled), ['1', 'true', 'on', 'yes'], true);
        }
        if (empty($data['ai']['apiKeys']) || !$data['ai']['enabled']) {
            $data['ai']['enabled'] = false;
        }
    }

    private static function validate(array $data): void
    {
        $storage = $data['storage'] ?? [];
        $storageTime = (int) ($storage['storageTime'] ?? 0);
        if ($storageTime <= 0) {
            throw new \InvalidArgumentException('storage.storageTime must be greater than zero');
        }

        $cache = $data['cache'] ?? [];
        $redis = $cache['redis'] ?? [];
        if (($redis['host'] ?? '') === '' || (int) ($redis['port'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('cache.redis host and port are required');
        }

        $ai = $data['ai'] ?? [];
        if (($ai['enabled'] ?? false) === true) {
            if (empty($ai['apiKeys']) || !is_array($ai['apiKeys'])) {
                throw new \InvalidArgumentException('AI_API_KEYS is required when AI is enabled');
            }
            if (!filter_var($ai['baseUrl'] ?? '', FILTER_VALIDATE_URL) || ($ai['model'] ?? '') === '') {
                throw new \InvalidArgumentException('AI_BASE_URL and AI_MODEL are required when AI is enabled');
            }
        }

        if (($ai['rag']['enabled'] ?? false) === true) {
            $providers = $ai['rag']['providers'] ?? [];
            if (!is_array($providers) || $providers === []) {
                throw new \InvalidArgumentException('AI_RAG_PROVIDERS is required when semantic RAG is enabled');
            }
            foreach ($providers as $provider) {
                if (!is_array($provider) || ($provider['name'] ?? '') === '' || !filter_var($provider['baseUrl'] ?? '', FILTER_VALIDATE_URL) || ($provider['apiKey'] ?? '') === '' || ($provider['embeddingModel'] ?? '') === '') {
                    throw new \InvalidArgumentException('Each AI_RAG_PROVIDERS entry requires name, baseUrl, apiKey and embeddingModel');
                }
            }
        }
    }

    public static function Get(string $name): array
    {
        if (!self::$loaded) {
            self::load(CORE_PATH . '/Config.inc.php');
        }
        return self::$data[$name] ?? [];
    }

    public static function has(string $name): bool
    {
        return isset(self::$data[$name]);
    }
}
