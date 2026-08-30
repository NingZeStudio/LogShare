<?php

// composer.json autoload.files also loads this file, so guard against a second
// require (which would otherwise redefine CORE_PATH).
if (defined('CORE_PATH')) {
    return;
}

define('CORE_PATH', dirname(__FILE__));

$dotenvPath = CORE_PATH . '/.env';
if (is_file($dotenvPath)) {
    foreach (file($dotenvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        // 不支持行内注释语法（`FOO=bar # comment` 的 # 会被并入值）；如需注释请整行书写。
        // 这里仍剥离未加空格直接跟在值后的 "#" 之后内容可能误伤合法值（如 URL 片段），
        // 故不做行内剥离，仅在注释中声明限制。
        if ($name !== '' && getenv($name) === false) {
            putenv($name . '=' . trim($value, "\\\"'"));
        }
    }
}

require_once(CORE_PATH . '/vendor/autoload.php');

\App\Config::load(CORE_PATH . '/Config.inc.php');
