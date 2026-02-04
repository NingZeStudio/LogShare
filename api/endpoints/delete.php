<?php

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    $error = new ApiError(405, "Method not allowed. Only DELETE requests are allowed for this endpoint.");
    $error->output();
}

// Extract log ID from the URL path
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$segments = explode('/', trim($path, '/'));
$logId = $segments[count($segments) - 1] ?? null;

if (!$logId) {
    $error = new ApiError(400, "Log ID is required");
    $error->output();
}

// Validate the log ID format
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $logId)) {
    $error = new ApiError(400, "Invalid log ID format");
    $error->output();
}

$id = new Id($logId);
$log = new Log($id);

if (!$log->exists()) {
    $error = new ApiError(404, "Log not found");
    $error->output();
}

// Attempt to delete the log
$deleted = $log->delete();

if ($deleted) {
    $response = new stdClass();
    $response->success = true;
    $response->message = "Log deleted successfully";
    
    header('Content-Type: application/json');
    echo json_encode($response);
} else {
    $error = new ApiError(500, "Failed to delete log");
    $error->output();
}