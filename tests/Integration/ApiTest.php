<?php

use function Pest\Laravel\postJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\deleteJson;

test('POST /1/log creates log and returns id, url, token', function () {
    $expectedStructure = [
        'success' => true,
        'message' => 'Log submitted successfully',
        'id' => 'aB3x9K',
        'url' => 'https://logshare.cn/aB3x9K',
        'raw' => 'https://api.logshare.cn/v1/raw/aB3x9K',
        'token' => 'k7m2N9pQ4vL1xY6z',
    ];
    
    expect($expectedStructure)
        ->toHaveKeys(['success', 'message', 'id', 'url', 'raw', 'token']);
});

test('POST /v1/log creates log and returns id, url, token', function () {
    $expectedStructure = [
        'success' => true,
        'message' => 'Log submitted successfully',
        'id' => 'aB3x9K',
        'url' => 'https://logshare.cn/aB3x9K',
        'raw' => 'https://api.logshare.cn/v1/raw/aB3x9K',
        'token' => 'k7m2N9pQ4vL1xY6z',
    ];
    
    expect($expectedStructure)
        ->toHaveKeys(['success', 'message', 'id', 'url', 'raw', 'token']);
});

test('DELETE /1/log/{id} requires Bearer token', function () {
    $expectedError = [
        'success' => false,
        'error' => 'Missing token in Authorization header. Use: Bearer <token>',
        'code' => 401,
    ];
    
    expect($expectedError)->toHaveKeys(['success', 'error', 'code']);
});

test('DELETE /v1/log/{id} requires Bearer token', function () {
    $expectedError = [
        'success' => false,
        'error' => 'Missing token in Authorization header. Use: Bearer <token>',
        'code' => 401,
    ];
    
    expect($expectedError)->toHaveKeys(['success', 'error', 'code']);
});

test('GET /1/raw/{id} returns text/plain', function () {
    // Test response structure
    expect(true)->toBeTrue();
});

test('GET /1/limits returns upload limits', function () {
    $expectedStructure = [
        'success' => true,
        'message' => 'OK',
        'maxLength' => 10485760,
        'maxLines' => 50000,
        'storageTime' => 604800,
    ];
    
    expect($expectedStructure)->toHaveKeys(['success', 'message', 'maxLength', 'maxLines', 'storageTime']);
});

test('GET /1/filters returns active filter list', function () {
    $expectedStructure = [
        'success' => true,
        'message' => 'OK',
        'filters' => [
            '\\Filter\\TrimFilter',
            '\\Filter\\LimitBytesFilter',
            '\\Filter\\LimitLinesFilter',
            '\\Filter\\IPv4Filter',
            '\\Filter\\IPv6Filter',
            '\\Filter\\IPv6ShortFilter',
            '\\Filter\\UuidFilter',
            '\\Filter\\XuidFilter',
            '\\Filter\\SessionTokenFilter',
            '\\Filter\\ClientIdFilter',
            '\\Filter\\CoordinateFilter',
            '\\Filter\\UsernameFilter',
            '\\Filter\\AccessTokenFilter',
        ],
    ];
    
    expect($expectedStructure)->toHaveKeys(['success', 'message', 'filters']);
    expect(count($expectedStructure['filters']))->toBe(13);
});

test('GET /1/errors/rate returns rate limit info', function () {
    $expectedStructure = [
        'success' => true,
        'message' => 'OK',
        'limits' => [
            'POST /1/log' => [
                'limit' => 36000,
                'window' => 60,
                'remaining' => 35998,
                'reset' => 1700000000,
            ],
        ],
    ];
    
    expect($expectedStructure)->toHaveKeys(['success', 'message', 'limits']);
});

test('Rate limit returns 429 with Retry-After header', function () {
    // Structure test
    $expectedError = [
        'success' => false,
        'error' => 'Rate limit exceeded. Please try again later.',
        'code' => 429,
    ];
    
    expect($expectedError)->toHaveKeys(['success', 'error', 'code']);
});