<?php

try {
    RequestValidator::validateMethod('GET');
    $logId = RequestValidator::extractId('/1/raw/');
} catch (ApiError $e) {
    $e->output();
}

$id = new Id($logId);
$log = new Log($id);

if (!$log->exists()) {
    $error = new ApiError(404, "Log not found.");
    $error->output();
}

$log->renew();

ApiResponse::text($log->get()->getLogfile()->getContent(), 'text/plain');
