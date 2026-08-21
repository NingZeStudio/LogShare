<?php

// composer.json autoload.files also loads this file, so guard against a second
// require (which would otherwise redefine CORE_PATH).
if (defined('CORE_PATH')) {
    return;
}

define('CORE_PATH', dirname(__FILE__));

require_once(CORE_PATH . '/vendor/autoload.php');

\App\Config::load(CORE_PATH . '/Config.inc.php');
