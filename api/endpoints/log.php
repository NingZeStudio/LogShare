<?php

use Data\Token;

try {
    RequestValidator::validateMethod('POST');
} catch (ApiError $e) {
    $e->output();
}

$content = (new ContentParser())->getContent();

if ($content instanceof ApiError) {
    $content->output();
}

// Handle array response from JSON (with metadata and source)
$metadata = [];
$source = null;
if (is_array($content)) {
    $metadata = $content['metadata'] ?? [];
    $source = $content['source'] ?? null;
    $content = $content['content'];
}

$log = new Log();
$token = new Token();
$id = $log->put($content, $token, $metadata, $source);

$urls = Config::Get('urls');

ApiResponse::success([
    'id' => $id->get(),
    'url' => $urls['baseUrl'] . "/" . $id->get(),
    'raw' => $urls['apiBaseUrl'] . "/1/raw/" . $id->get(),
    'token' => $token->get()
], 'Log submitted successfully');
