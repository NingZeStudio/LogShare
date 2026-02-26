<?php

try {
    RequestValidator::validateMethod('GET');
} catch (ApiError $e) {
    $e->output();
}

$config = Config::Get('storage');

ApiResponse::json([
    'storageTime' => $config['storageTime'],
    'maxLength' => $config['maxLength'],
    'maxLines' => $config['maxLines']
]);

