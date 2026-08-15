<?php

use Cache\CacheEntry;

beforeEach(function () {
    $configRef = new ReflectionClass(\Config::class);
    $dataProp = $configRef->getProperty('data');
    $this->origData = $dataProp->getValue();

    $data = $this->origData;
    $data['cache']['cacheId'] = '\\Cache\\RedisCache';
    $data['cache']['enabled'] = true;
    $data['cache']['redis'] = ['host' => 'localhost', 'port' => 6379];
    $dataProp->setValue(null, $data);
});

afterEach(function () {
    $configRef = new ReflectionClass(\Config::class);
    $dataProp = $configRef->getProperty('data');
    $dataProp->setValue(null, $this->origData);
});

test('CacheEntry set and get round-trips through the cache backend', function () {
    $entry = new CacheEntry('cache:test:key');
    expect($entry->exists())->toBeFalse();

    $entry->set('cached value', 60);
    expect($entry->exists())->toBeTrue();
    expect($entry->get())->toBe('cached value');
});

test('CacheEntry get returns null for missing keys', function () {
    $entry = new CacheEntry('cache:missing:key');
    expect($entry->get())->toBeNull();
    expect($entry->exists())->toBeFalse();
});

test('CacheEntry without configured backend is a no-op', function () {
    $configRef = new ReflectionClass(\Config::class);
    $dataProp = $configRef->getProperty('data');
    $data = $dataProp->getValue();
    $data['cache']['cacheId'] = null;
    $dataProp->setValue(null, $data);

    $entry = new CacheEntry('cache:noop:key');
    expect($entry->get())->toBeNull();
    expect($entry->exists())->toBeFalse();

    // Must not throw
    $entry->set('value', 60);
});