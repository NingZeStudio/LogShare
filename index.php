<?php

require_once(__DIR__ . '/core.php');

// Global exception handler: converts any uncaught exception into a JSON error
// response instead of leaking a PHP fatal/stack trace.
set_exception_handler(function (\Throwable $e) {
    if ($e instanceof ApiError) {
        $e->output();
    }

    error_log("[Fatal] " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    ApiResponse::error('Internal server error.', 500);
});

try {
    $storageConfig = \Config::Get('storage');
    if (($storageConfig['storageId'] ?? null) === 'm') {
        \Client\MongoDBClient::ensureIndexes();
    }
} catch (\Throwable $e) {
    error_log("Failed to ensure MongoDB indexes: " . $e->getMessage());
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header("Accept-Encoding: " . implode(",", ContentParser::getSupportedEncodings()));
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

Router::dispatch();
