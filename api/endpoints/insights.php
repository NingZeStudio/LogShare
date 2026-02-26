<?php

try {
    RequestValidator::validateMethod('GET');
    $logId = RequestValidator::extractId('/1/insights/');
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

$codexLog = $log->get();
$codexLog->setIncludeEntries(false);

ApiResponse::json($codexLog);
