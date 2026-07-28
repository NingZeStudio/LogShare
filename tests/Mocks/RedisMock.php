<?php

namespace Tests\Mocks;

class RedisMock
{
    private static array $data = [];
    private static array $ttl = [];

    public function __construct() {}

    public function connect(): bool { return true; }
    public function auth(): bool { return true; }
    public function select(): bool { return true; }

    public function get(string $key): string|false
    {
        if (!isset(self::$data[$key])) {
            return false;
        }
        if (isset(self::$ttl[$key]) && self::$ttl[$key] < time()) {
            unset(self::$data[$key], self::$ttl[$key]);
            return false;
        }
        return self::$data[$key];
    }

    public function set(string $key, string $value, string|int $mode = 'EX', int $ttl = 0): bool
    {
        self::$data[$key] = $value;
        if ($ttl > 0) {
            self::$ttl[$key] = time() + $ttl;
        }
        return true;
    }

    public function del(string ...$keys): int
    {
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
        $val = (int)(self::$data[$key] ?? 0) + 1;
        self::$data[$key] = (string)$val;
        return $val;
    }

    public function expire(string $key, int $ttl): bool
    {
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

    public static function reset(): void
    {
        self::$data = [];
        self::$ttl = [];
    }
}