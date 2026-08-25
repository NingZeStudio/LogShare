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
        if (is_array($data)) {
            self::applyEnvironmentOverrides($data);
            self::$data = $data;
            self::$loaded = true;
        }
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
     *  - AI_RAG_BASE_URL    → ai.rag.baseUrl
     *  - AI_RAG_API_KEY     → ai.rag.apiKey
     *
     * @param array $data Config array by reference
     * @return void
     */
    private static function applyEnvironmentOverrides(array &$data): void
    {
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
        if (($url = getenv('AI_RAG_BASE_URL')) !== false && $url !== '') {
            $data['ai']['rag']['baseUrl'] = $url;
        }
        if (($key = getenv('AI_RAG_API_KEY')) !== false && $key !== '') {
            $data['ai']['rag']['apiKey'] = $key;
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
