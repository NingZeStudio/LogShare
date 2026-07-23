<?php

require_once(__DIR__ . '/core.php');

try {
    \Client\MongoDBClient::ensureIndexes();
} catch (\Exception $e) {
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
