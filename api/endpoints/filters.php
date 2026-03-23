<?php

require_once("../../core/core.php");

try {
    RequestValidator::validateMethod('GET');
} catch (ApiError $e) {
    $e->output();
}

// 返回所有过滤器的信息
$filters = [
    [
        'type' => 'trim',
        'data' => null
    ],
    [
        'type' => 'limit-bytes',
        'data' => [
            'limit' => Config::Get('storage')['maxLength']
        ]
    ],
    [
        'type' => 'limit-lines',
        'data' => [
            'limit' => Config::Get('storage')['maxLines']
        ]
    ],
    [
        'type' => 'regex',
        'data' => [
            'patterns' => [
                [
                    'pattern' => 'IPv4',
                    'replacement' => '**.**.**.**'
                ],
                [
                    'pattern' => 'IPv6',
                    'replacement' => '****:****:****:****:****:****:****:****'
                ],
                [
                    'pattern' => 'Username',
                    'replacement' => '********'
                ],
                [
                    'pattern' => 'AccessToken',
                    'replacement' => '********'
                ]
            ]
        ]
    ]
];

ApiResponse::json([
    'success' => true,
    'filters' => $filters
]);
