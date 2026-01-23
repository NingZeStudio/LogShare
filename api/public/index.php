<?php

require_once("../../core/core.php");

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header("Accept-Encoding: " . implode(",", ContentParser::getSupportedEncodings()));

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

switch ($_SERVER['REQUEST_URI']) {
    case "/":
        require_once("../frontend/main.php");
        break;
    case "/1/log":
    case "/1/log/":
        require_once("../endpoints/log.php");
        break;
    case "/1/analyse":
    case "/1/analyse/":
        require_once("../endpoints/analyse.php");
        break;
    case "/1/errors/rate":
        require_once("../endpoints/rate-error.php");
        break;
    case "/1/limits":
        require_once("../endpoints/limits.php");
        break;
    default:
        if (preg_match('#^/1/raw/#', $_SERVER['REQUEST_URI'])) {
            require_once("../endpoints/raw.php");
            break;
        }
        if (preg_match('#^/1/ai-analysis/#', $_SERVER['REQUEST_URI'])) {
            require_once("../endpoints/ai-analysis.php");
            break;
        }
        if (preg_match('#^/1/insights/#', $_SERVER['REQUEST_URI'])) {
            require_once("../endpoints/insights.php");
            break;
        }

        $error = new ApiError(404, "Could not find endpoint. URI: " . $_SERVER['REQUEST_URI']);
        $error->output();
}
