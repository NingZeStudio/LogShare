<?php

use Storage\FilesystemStorage;

beforeEach(function () {
    $this->configRef = new ReflectionClass(\Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();
    $this->tmpDir = CORE_PATH . '/tmp/logshare_test_' . uniqid();
    mkdir($this->tmpDir, 0777, true);
});

afterEach(function () {
    if (is_dir($this->tmpDir)) {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }
    $this->dataProp->setValue(null, $this->origData);
});

test('FilesystemStorage persists additional files under the same id', function () {
    $data = $this->dataProp->getValue();
    $data['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $this->dataProp->setValue(null, $data);

    $main = "[12:34:56] [Server thread/INFO]: Starting minecraft server version 1.20.1\n";
    $crash = "---- Minecraft Crash Report ----\njava.lang.OutOfMemoryError\nat net.minecraft.class_1000\n";
    $debug = "GL: OpenGL 3.2";

    $id = FilesystemStorage::Put($main, null, [], 'test', [
        ['name' => 'crash-reports/crash-01.txt', 'data' => $crash],
        ['name' => 'debug.txt', 'data' => $debug],
    ]);

    expect($id)->toBeInstanceOf(\Id::class);

    $result = FilesystemStorage::Get($id);
    expect($result['data'])->toBe($main);
    expect($result['files'])->toHaveCount(2);

    $crashFile = $result['files'][0];
    expect($crashFile['name'])->toBe('crash-reports/crash-01.txt');
    expect($crashFile['data'])->toBe($crash);
    expect($crashFile['size'])->toBe(strlen($crash));

    $meta = FilesystemStorage::Get($id, false);
    expect($meta['files'][0])->toHaveKeys(['name', 'size']);
    expect($meta['files'][0])->not->toHaveKey('data');

    expect(FilesystemStorage::Delete($id))->toBeTrue();
    expect(FilesystemStorage::Get($id))->toBeNull();
});

test('FilesystemStorage Get omits file content when includeContent is false and keeps data', function () {
    $data = $this->dataProp->getValue();
    $data['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $this->dataProp->setValue(null, $data);

    $id = FilesystemStorage::Put('main', null, [], null, [
        ['name' => 'extra.log', 'data' => 'content'],
    ]);

    $meta = FilesystemStorage::Get($id, false);
    expect($meta['data'])->toBe('main');
    expect($meta['files'])->toHaveCount(1);
    expect($meta['files'][0])->toMatchArray(['name' => 'extra.log', 'size' => 7]);
    expect($meta['files'][0])->not->toHaveKey('data');

    FilesystemStorage::Delete($id);
});

test('Log exposes additional file accessors', function () {
    $log = new \Log();
    $ref = new ReflectionClass(\Log::class);
    $prop = $ref->getProperty('files');
    $prop->setValue($log, [
        ['name' => 'a.log', 'data' => "line1\nline2\nline3", 'size' => 17],
        ['name' => 'sub/b.log', 'data' => 'solo', 'size' => 4],
    ]);

    expect($log->getFiles())->toHaveCount(2);
    expect($log->getFiles()[0])->toMatchArray(['name' => 'a.log', 'size' => 17]);
    expect($log->getFiles()[0])->not->toHaveKey('data');

    expect($log->getFile('a.log'))->toBe("line1\nline2\nline3");
    expect($log->getFile('sub/b.log'))->toBe('solo');
    expect($log->getFile('missing.log'))->toBeNull();

    expect($log->hasFile('a.log'))->toBeTrue();
    expect($log->hasFile('nope'))->toBeFalse();

    expect($log->getFileLineNumbers('a.log'))->toBe(3);
    expect($log->getFileLineNumbers('sub/b.log'))->toBe(1);
    expect($log->getFileLineNumbers('missing'))->toBe(0);
});

test('Log put applies pre filters to additional files', function () {
    $data = $this->dataProp->getValue();
    $data['storage']['storages']['f'] = [
        'name' => 'Filesystem',
        'class' => '\\Storage\\FilesystemStorage',
        'enabled' => true,
    ];
    $data['storage']['storageId'] = 'f';
    $data['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $data['cache']['enabled'] = false;
    $this->dataProp->setValue(null, $data);

    $ip = 'AccessToken: 550e8400-e29b-41d4-a716-446655440000';
    $log = new \Log();
    $id = $log->put('main line ' . $ip, null, [], 's', [
        ['name' => 'extra.log', 'data' => 'extra ' . $ip],
    ]);

    expect($id)->not->toBeNull();
    $loaded = new \Log($id);
    expect($loaded->exists())->toBeTrue();
    expect($loaded->getContent())->not->toContain('550e8400');
    expect($loaded->getFile('extra.log'))->not->toContain('550e8400');
    expect($loaded->getFile('extra.log'))->toContain('extra ');

    $loaded->delete();
});