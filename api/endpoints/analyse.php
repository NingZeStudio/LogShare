<?php

try {
    RequestValidator::validateMethod('POST');
} catch (ApiError $e) {
    $e->output();
}

$content = (new ContentParser())->getContent();

if ($content instanceof ApiError) {
    $content->output();
}

$log = new Log();
$log->setData($content);
$log->analyse();

$codexLog = $log->get();
$codexLog->setIncludeEntries(false);

ApiResponse::json($codexLog);
