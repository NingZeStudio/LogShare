<?php

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    $error = new ApiError(405, "Method not allowed. Only DELETE requests are allowed for this endpoint.");
    $error->output();
}

/**
 * 验证单个 ID 格式
 *
 * @param string $id
 * @return bool
 */
function isValidIdFormat(string $id): bool
{
    return preg_match('/^[a-zA-Z0-9_-]+$/', $id);
}

/**
 * 从 URL 路径提取日志 ID(s)
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$segments = explode('/', trim($path, '/'));
$idSegment = $segments[count($segments) - 1] ?? null;

if (!$idSegment) {
    $error = new ApiError(400, "Log ID is required");
    $error->output();
}

// 支持多 ID 删除：使用逗号分隔
$logIds = explode(',', $idSegment);
$logIds = array_map('trim', $logIds);
$logIds = array_filter($logIds, fn($id) => !empty($id));

if (empty($logIds)) {
    $error = new ApiError(400, "At least one valid log ID is required");
    $error->output();
}

// 获取 Authorization header 中的 token
$authorizationHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$requestToken = null;
if ($authorizationHeader) {
    $parts = explode(" ", $authorizationHeader);
    $requestToken = $parts[1] ?? null;
}

if (!$requestToken) {
    $error = new ApiError(400, "Missing token in Authorization header.");
    $error->output();
}

// 验证每个 ID 并检查 token
$results = [
    'deleted' => [],
    'failed' => []
];

foreach ($logIds as $logId) {
    if (!isValidIdFormat($logId)) {
        $results['failed'][] = [
            'id' => $logId,
            'message' => "Invalid log ID format: {$logId}",
            'code' => 400
        ];
        continue;
    }

    $id = new Id($logId);
    $log = new Log($id);

    if (!$log->exists()) {
        $results['failed'][] = [
            'id' => $logId,
            'message' => "Log not found: {$logId}",
            'code' => 404
        ];
        continue;
    }

    if (!$log->verifyToken($requestToken)) {
        $results['failed'][] = [
            'id' => $logId,
            'message' => "Invalid token for log: {$logId}",
            'code' => 403
        ];
        continue;
    }

    $deleted = $log->delete();
    if ($deleted) {
        $results['deleted'][] = $logId;
    } else {
        $results['failed'][] = [
            'id' => $logId,
            'message' => "Failed to delete log: {$logId}",
            'code' => 500
        ];
    }
}

$response = new stdClass();
$response->success = !empty($results['deleted']);
$response->deleted = $results['deleted'];
$response->failed = $results['failed'];
$response->total = count($logIds);
$response->deletedCount = count($results['deleted']);
$response->failedCount = count($results['failed']);

// 如果全部失败，返回错误
if (empty($results['deleted'])) {
    $errorMessages = array_column($results['failed'], 'message');
    $error = new ApiError(400, "Failed to delete logs: " . implode(', ', $errorMessages));
    $error->output();
}

// 部分成功或全部成功
header('Content-Type: application/json');
echo json_encode($response);