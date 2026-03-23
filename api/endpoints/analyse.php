<?php

try {
    RequestValidator::validateMethod('POST');
} catch (ApiError $e) {
    $e->output();
}

$contentResult = (new ContentParser())->getContent();

if ($contentResult instanceof ApiError) {
    $contentResult->output();
}

// ContentParser 返回的是数组，需要提取 content 字段
$content = is_array($contentResult) ? $contentResult['content'] : $contentResult;

$log = new Log();
$log->setData($content);
$log->analyse();

$codexLog = $log->get();
$codexLog->setIncludeEntries(false);

ApiResponse::json($codexLog);
