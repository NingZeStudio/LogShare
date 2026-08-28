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
        if ($name !== '' && getenv($name) === false) {
            putenv($name . '=' . trim($value, "\\\"'"));
        }
    }
}

require_once(CORE_PATH . '/vendor/autoload.php');

\App\Config::load(CORE_PATH . '/Config.inc.php');
