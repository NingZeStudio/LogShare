<?php

use Client\SpinYarnClient;

test('SpinYarnClient reports unavailable when extension is not loaded', function () {
    // 常规测试环境不加载 spinyarn 扩展
    expect(SpinYarnClient::isAvailable())->toBeFalse();
});

test('SpinYarnClient degrades to null when extension is absent', function () {
    $result = SpinYarnClient::deobfuscate(
        "at net.minecraft.class_310.method_55608(x.java:1)",
        '1.20.1',
        'yarn'
    );
    expect($result)->toBeNull();
});

test('SpinYarnClient degrades to null for unconfigured mappings dir', function () {
    $result = SpinYarnClient::deobfuscate('some log', '1.20.1', 'vanilla');
    expect($result)->toBeNull();
});