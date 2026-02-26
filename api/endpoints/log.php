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
$id = $log->put($content);

$urls = Config::Get('urls');

ApiResponse::success([
    'id' => $id->get(),
    'url' => $urls['baseUrl'] . "/" . $id->get(),
    'raw' => $urls['apiBaseUrl'] . "/1/raw/" . $id->get()
], 'Log submitted successfully');
