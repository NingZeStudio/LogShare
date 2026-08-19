<?php

beforeEach(function () {
    $this->configRef = new ReflectionClass(\App\Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();
});

afterEach(function () {
    $this->dataProp->setValue(null, $this->origData);
});

function parseJsonViaContentParser(array $data): string|App\ApiError|array
{
    $parser = new App\ContentParser();
    $ref = new ReflectionClass(App\ContentParser::class);
    $method = $ref->getMethod('parseJsonData');
    return $method->invoke($parser, $data);
}

test('parseJsonData extracts content, metadata and source', function () {
    $result = parseJsonViaContentParser([
        'content' => 'log line',
        'metadata' => [['key' => 'k', 'value' => 'v']],
        'source' => 'my-server',
    ]);

    expect($result)->toBeArray();
    expect($result['content'])->toBe('log line');
    expect($result['source'])->toBe('my-server');
    expect($result['metadata'])->toHaveCount(1);
});

test('parseJsonData requires content', function () {
    $result = parseJsonViaContentParser([]);
    expect($result)->toBeInstanceOf(App\ApiError::class);
    expect($result->getHttpCode())->toBe(400);

    $result = parseJsonViaContentParser(['content' => '']);
    expect($result)->toBeInstanceOf(App\ApiError::class);

    $result = parseJsonViaContentParser(['content' => 123]);
    expect($result)->toBeInstanceOf(App\ApiError::class);
});

test('parseJsonData allows omitted content when files are provided', function () {
    $result = parseJsonViaContentParser([
        'files' => [
            ['name' => 'latest.log', 'content' => "[12:00] INFO: start\n"],
        ],
    ]);

    expect($result)->toBeArray();
    expect($result['content'])->toBe('');
    expect($result['files'])->toHaveCount(1);
});

test('parseJsonData parses files array via App\UploadParser', function () {
    $result = parseJsonViaContentParser([
        'content' => 'main',
        'files' => [
            ['name' => 'a.log', 'content' => 'aaa'],
            ['name' => 'b.log', 'content' => 'bbb'],
        ],
    ]);

    expect($result)->toBeArray();
    expect($result['files'])->toHaveCount(2);
    expect($result['files'][0])->toMatchArray(['name' => 'a.log', 'data' => 'aaa']);
});

test('parseJsonData rejects invalid files via App\UploadParser', function () {
    $result = parseJsonViaContentParser([
        'content' => 'main',
        'files' => [
            ['name' => '../evil.log', 'content' => 'bad'],
        ],
    ]);

    expect($result)->toBeInstanceOf(App\ApiError::class);
    expect($result->getHttpCode())->toBe(400);
});

test('parseJsonData rejects non-array files field', function () {
    $result = parseJsonViaContentParser(['content' => 'x', 'files' => 'not-an-array']);
    expect($result)->toBeArray();
    expect(isset($result['files']))->toBeFalse();
});

test('parseJsonData truncates source to 64 chars', function () {
    $result = parseJsonViaContentParser([
        'content' => 'x',
        'source' => str_repeat('s', 100),
    ]);

    expect(strlen($result['source']))->toBeLessThanOrEqual(64);
});