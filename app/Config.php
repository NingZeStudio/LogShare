<?php

namespace App;

class Config
{
    private static array $data = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
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
     *  - MONGODB_URI        → mongo.url
     *  - REDIS_HOST         → cache.redis.host
     *  - REDIS_PORT         → cache.redis.port
     *  - REDIS_TIMEOUT      → cache.redis.timeout
     *  - AI_API_KEYS        → ai.apiKeys (comma-separated)
     *  - AI_BASE_URL        → ai.baseUrl
     *  - AI_MODEL           → ai.model
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

        if ($keys = getenv('AI_API_KEYS')) {
            $data['ai']['apiKeys'] = array_values(array_filter(array_map('trim', explode(',', $keys)), fn($k) => $k !== ''));
        }
        if ($baseUrl = getenv('AI_BASE_URL')) {
            $data['ai']['baseUrl'] = $baseUrl;
        }
        if ($model = getenv('AI_MODEL')) {
            $data['ai']['model'] = $model;
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
