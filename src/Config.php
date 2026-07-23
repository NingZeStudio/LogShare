<?php

class Config
{
    private static array $data = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        $data = require $path;
        if (is_array($data)) {
            self::$data = $data;
            self::$loaded = true;
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
