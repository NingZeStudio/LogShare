<?php

require_once("../../core/core.php");

try {
    RequestValidator::validateMethod('POST');
} catch (ApiError $e) {
    $e->output();
}

$content = (new ContentParser())->getContent();

if ($content instanceof ApiError) {
    $content->output();
}

// JSON 格式：[{"id": "...", "token": "..."}, ...]
if (!is_array($content)) {
    $error = new ApiError(400, "Request body must be a JSON array of log objects with 'id' and 'token' fields.");
    $error->output();
}

if (count($content) === 0) {
    $error = new ApiError(400, "No logs provided.");
}

if (count($content) > 256) {
    $error = new ApiError(400, "Too many logs. Maximum is 256.");
}

$results = [];
$deleteIds = [];

foreach ($content as $logEntry) {
    if (!is_array($logEntry)) {
        $error = new ApiError(400, "Each entry must be an object with 'id' and 'token' fields.");
        $error->output();
    }
    
    if (!isset($logEntry["id"]) || !is_string($logEntry["id"])) {
        $error = new ApiError(400, "Each log must have a valid 'id' field.");
        $error->output();
    }
    
    if (!isset($logEntry["token"]) || !is_string($logEntry["token"])) {
        $error = new ApiError(400, "Each log must have a valid 'token' field.");
        $error->output();
    }
    
    $id = new Id($logEntry["id"]);
    $token = $logEntry["token"];
    
    $log = new Log($id);
    
    if (!$log->exists()) {
        $results[$logEntry["id"]] = [
            'success' => false,
            'error' => 'Log not found.',
            'code' => 404
        ];
        continue;
    }
    
    if (!$log->verifyToken($token)) {
        $results[$logEntry["id"]] = [
            'success' => false,
            'error' => 'Invalid token.',
            'code' => 403
        ];
        continue;
    }
    
    $deleteIds[] = $id;
    $results[$logEntry["id"]] = [
        'success' => true
    ];
}

// 执行批量删除
foreach ($deleteIds as $id) {
    $config = Config::Get('storage');
    $storage = $config['storages'][$id->getStorage()]['class'];
    $storage::Delete($id);
}

// 构建响应
$response = [
    'success' => true,
    'results' => $results
];

ApiResponse::json($response);
