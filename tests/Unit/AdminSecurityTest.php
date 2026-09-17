<?php

use App\ApiError;
use App\Config;
use App\Controller\AdminController;
use App\Controller\LogController;
use App\System\SecurityService;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response;

beforeEach(function () {
    $this->configRef = new ReflectionClass(Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $this->tmpDir = CORE_PATH . '/tmp/logshare_sec_test_' . uniqid();
    mkdir($this->tmpDir, 0777, true);

    $cfg = $this->origData;
    $cfg['admin'] = [
        'enabled' => true,
        'token' => 'test-admin-secret-token',
    ];
    $cfg['filesystem']['path'] = substr($this->tmpDir, strlen(CORE_PATH)) . '/';
    $cfg['storage']['storageId'] = 'f';
    $cfg['storage']['storages']['f']['enabled'] = true;
    $this->dataProp->setValue(null, $cfg);

    if (!\Hyperf\Context\ApplicationContext::hasContainer()) {
        $container = Mockery::mock(\Psr\Container\ContainerInterface::class);
        $container->shouldReceive('has')->andReturn(true);
        $container->shouldReceive('get')->andReturnUsing(function ($class) {
            if ($class === \Hyperf\HttpServer\Contract\RequestInterface::class) {
                return new \Hyperf\HttpServer\Request();
            }
            if ($class === \Hyperf\HttpServer\Response::class) {
                return new \Hyperf\HttpServer\Response();
            }
            return null;
        });
        \Hyperf\Context\ApplicationContext::setContainer($container);
    }
});

afterEach(function () {
    if (is_dir($this->tmpDir)) {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tmpDir);
    }
    $rulesFile = CORE_PATH . '/runtime/content_reject_rules.json';
    if (is_file($rulesFile)) {
        @unlink($rulesFile);
    }
    $this->dataProp->setValue(null, $this->origData);
});

test('SecurityService ban, unban, and isIpBanned lifecycle', function () {
    $testIp = '198.51.100.42';

    // 初始未封禁
    expect(SecurityService::isIpBanned($testIp))->toBeFalse();

    // 封禁
    $banResult = SecurityService::banIp($testIp, 3600, 'Malicious crawling test');
    expect($banResult['ip'])->toBe($testIp);
    expect($banResult['reason'])->toBe('Malicious crawling test');

    // 封禁状态与列表检查
    expect(SecurityService::isIpBanned($testIp))->toBeTrue();
    $list = SecurityService::getBannedIps();
    $found = array_filter($list, fn($item) => $item['ip'] === $testIp);
    expect($found)->not->toBeEmpty();

    // 解封
    SecurityService::unbanIp($testIp);
    expect(SecurityService::isIpBanned($testIp))->toBeFalse();
});

test('SecurityService content rules validation and rejection', function () {
    SecurityService::saveContentRules([
        'enabled' => true,
        'keywords' => ['bad-malware-payload', 'cheat-distro-link'],
        'patterns' => ['/trojan_v\d+/i'],
    ]);

    $rules = SecurityService::getContentRules();
    expect($rules['enabled'])->toBeTrue();
    expect($rules['keywords'])->toContain('bad-malware-payload');

    // 正常文本不抛出
    expect(fn() => SecurityService::validateContent('Normal minecraft log'))->not->toThrow(ApiError::class);

    // 包含违规关键词抛出 ApiError
    expect(fn() => SecurityService::validateContent('Some log with bad-malware-payload included'))
        ->toThrow(ApiError::class);

    // 匹配正则抛出 ApiError
    expect(fn() => SecurityService::validateContent('Detected signature trojan_v2'))
        ->toThrow(ApiError::class);
});

test('AdminController security endpoints respond properly', function () {
    $controller = new AdminController();
    $psr7Req = new Request('GET', '/v1/admin/security/overview');
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $psr7Req);
    $reqRef = new ReflectionProperty($controller, 'request');
    $reqRef->setValue($controller, new \Hyperf\HttpServer\Request());

    $overviewResp = $controller->getSecurityOverview();
    expect($overviewResp->getStatusCode())->toBe(200);
    $overviewBody = json_decode((string) $overviewResp->getBody(), true);
    expect($overviewBody['success'])->toBeTrue();
    expect($overviewBody)->toHaveKey('categories');

    // 封禁测试
    $banReq = (new Request('POST', '/v1/admin/security/ban'))->withParsedBody([
        'ip' => '203.0.113.88',
        'ttl' => 600,
        'reason' => 'Admin test ban',
    ]);
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $banReq);
    $banResp = $controller->banIp();
    expect($banResp->getStatusCode())->toBe(200);

    // 查列表
    $listReq = new Request('GET', '/v1/admin/security/bans');
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $listReq);
    $listResp = $controller->getSecurityBans();
    $listBody = json_decode((string) $listResp->getBody(), true);
    expect($listBody['success'])->toBeTrue();
    expect($listBody['total'])->toBeGreaterThanOrEqual(1);

    // 解封
    $unbanReq = (new Request('POST', '/v1/admin/security/unban'))->withParsedBody(['ip' => '203.0.113.88']);
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $unbanReq);
    $unbanResp = $controller->unbanIp();
    expect($unbanResp->getStatusCode())->toBe(200);
});
