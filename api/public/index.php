<?php

require_once("../../core/core.php");

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header("Accept-Encoding: " . implode(",", ContentParser::getSupportedEncodings()));
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

switch ($_SERVER['REQUEST_URI']) {
    case "/":
        echo json_encode([
            "message" => "Welcome to the API. Please use the following endpoints:",
            "endpoints" => [
                "POST /1/log" => "Submit log data",
                "POST /1/analyse" => "Analyze log data",
                "GET /1/errors/rate" => "Get error rate statistics",
                "GET /1/limits" => "Get API rate limits",
                "GET /1/raw/{id}" => "Retrieve raw log by ID (supports multiple IDs: /1/raw/id1,id2,id3)",
                "GET /1/ai-analysis/{id}" => "Get AI analysis for log ID",
                "GET /1/insights/{id}" => "Get insights for log ID",
                "DELETE /1/delete/{id}" => "Delete log by ID (supports multiple IDs: /1/delete/id1,id2,id3)"
            ],
            "documentation" => "Please refer to the API documentation for detailed usage."
        ]);
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
        if (preg_match('#^/1/delete/#', $_SERVER['REQUEST_URI'])) {
            require_once("../endpoints/delete.php");
            break;
        }

        http_response_code(404);
        echo json_encode([
            "error" => "Endpoint not found",
            "uri" => $_SERVER['REQUEST_URI'],
            "message" => "Please check the available endpoints at /"
        ]);
        break;
}