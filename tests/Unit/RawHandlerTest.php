<?php

use Handler\RawHandler;
use Handler\LogMetaHandler;

beforeEach(function () {
    $this->configRef = new ReflectionClass(\Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $data = $this->origData;
    $data['storage']['storages']['f'] = [
        'name' => 'Filesystem',
        'class' => '\\Storage\\FilesystemStorage',
        'enabled' => true,
    ];
    $data['storage']['storageId'] = 'f';
    $data['cache']['enabled'] = false;

    $this->tmpDir = CORE_PATH . '/tmp/logshare_test_' . uniqid();
    mkdir($this->tmpDir, 0777, true);
    $data['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $this->dataProp->setValue(null, $data);
});

afterEach(function () {
    $this->dataProp->setValue(null, $this->origData);
    if (is_dir($this->tmpDir)) {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tmpDir);
    }
});

function createMultiFileLog(): \Log
{
    $log = new \Log();
    $id = $log->put(
        "[12:00] INFO: primary line\n",
        null,
        [],
        'test',
        [
            ['name' => 'crash-reports/crash-01.txt', 'data' => "java.lang.Error\nat a.b.c\n"],
            ['name' => 'debug.txt', 'data' => "GL: OpenGL 3.2\n"],
        ]
    );
    return new \Log($id);
}

test('Router matches raw id with nested filename', function () {
    $ref = new ReflectionClass(\Router::class);
    $method = $ref->getMethod('matchPath');

    $result = $method->invoke(null, '/v1/raw/{id}/{filename:.+}', '/v1/raw/aB3x9K/crash-reports/crash-01.txt');
    expect($result)->toMatchArray(['id' => 'aB3x9K', 'filename' => 'crash-reports/crash-01.txt']);
});

test('Router raw filename pattern does not match single segment', function () {
    $ref = new ReflectionClass(\Router::class);
    $method = $ref->getMethod('matchPath');

    $result = $method->invoke(null, '/v1/raw/{id}/{filename:.+}', '/v1/raw/aB3x9K');
    expect($result)->toBeNull();
});

test('Router matches single id raw pattern', function () {
    $ref = new ReflectionClass(\Router::class);
    $method = $ref->getMethod('matchPath');

    $result = $method->invoke(null, '/v1/raw/{id}', '/v1/raw/aB3x9K');
    expect($result)->toMatchArray(['id' => 'aB3x9K']);
});

test('RawHandler serves a named sub-file', function () {
    $log = createMultiFileLog();

    expect($log->getFile('crash-reports/crash-01.txt'))->toBe("java.lang.Error\nat a.b.c");
    expect($log->getFile('debug.txt'))->toBe("GL: OpenGL 3.2");
    expect($log->getFile('missing.txt'))->toBeNull();
    expect($log->getContent())->toBe("[12:00] INFO: primary line");
});

test('LogMetaHandler data includes file list', function () {
    $log = createMultiFileLog();

    expect($log->getFiles())->toHaveCount(2);
    $names = array_column($log->getFiles(), 'name');
    expect($names)->toContain('crash-reports/crash-01.txt');
    expect($names)->toContain('debug.txt');

    foreach ($log->getFiles() as $file) {
        expect($file)->toHaveKeys(['name', 'size']);
        expect($file)->not->toHaveKey('data');
    }
});

test('Log accessors handle nested filenames', function () {
    $log = createMultiFileLog();

    expect($log->getFileLineNumbers('crash-reports/crash-01.txt'))->toBe(2);
    expect($log->hasFile('debug.txt'))->toBeTrue();
    expect($log->hasFile('nested/deep/file.log'))->toBeFalse();
});