<?php

use App\Client\SpinYarnClient;

test('SpinYarnClient reports unavailable when extension is not loaded', function () {
    if (function_exists('spinyarn_deobfuscate')) {
        $this->markTestSkipped('spinyarn 扩展已加载，降级行为不适用');
    }
    // 常规测试环境不加载 spinyarn 扩展
    expect(SpinYarnClient::isAvailable())->toBeFalse();
});

test('SpinYarnClient degrades to null when extension is absent', function () {
    if (function_exists('spinyarn_deobfuscate')) {
        $this->markTestSkipped('spinyarn 扩展已加载，降级行为不适用');
    }
    $result = SpinYarnClient::deobfuscate(
        "at net.minecraft.class_310.method_55608(x.java:1)",
        '1.20.1',
        'yarn'
    );
    expect($result)->toBeNull();
});

test('SpinYarnClient degrades to null for unconfigured mappings dir', function () {
    if (function_exists('spinyarn_deobfuscate')) {
        $this->markTestSkipped('spinyarn 扩展已加载，降级行为不适用');
    }
    $result = SpinYarnClient::deobfuscate('some log', '1.20.1', 'vanilla');
    expect($result)->toBeNull();
});

test('resolveMappingsDir resolves relative paths against project root', function () {
    $ref = new ReflectionClass(SpinYarnClient::class);
    $method = $ref->getMethod('resolveMappingsDir');

    expect($method->invoke(null, ''))->toBeNull();
    expect($method->invoke(null, '  '))->toBeNull();
    expect($method->invoke(null, '/opt/spinyarn/mappings'))->toBe('/opt/spinyarn/mappings');
    expect($method->invoke(null, 'spinyarn/mappings'))->toBe(CORE_PATH . '/spinyarn/mappings');
});