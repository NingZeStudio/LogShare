<?php

// composer.json autoload.files also loads this file, so guard against a second
// require (which would otherwise redefine CORE_PATH and re-register the autoloader).
if (defined('CORE_PATH')) {
    return;
}

define('CORE_PATH', dirname(__FILE__));

require_once(CORE_PATH . '/vendor/autoload.php');

spl_autoload_register(function ($class) {
    $class = str_replace("\\", "/", $class);

    // The LogShare\ prefix maps to the same src/ directory as the legacy
    // global classes, so strip it for path resolution.
    if (str_starts_with($class, 'LogShare/')) {
        $class = substr($class, strlen('LogShare/'));
    }

    $classPath = CORE_PATH . '/src/' . $class . '.php';
    if (file_exists($classPath)) {
        include $classPath;
    }
});

Config::load(CORE_PATH . '/Config.inc.php');
