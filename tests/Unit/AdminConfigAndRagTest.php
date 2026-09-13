<?php

use App\ApiError;
use App\Config;
use App\Controller\AdminController;
use App\Rag\RagManager;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response;

beforeEach(function () {
    $this->configRef = new ReflectionClass(Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();

    $cfg = $this->origData;
    $cfg['admin'] = [
        'enabled' => true,
        'token' => 'test-admin-secret-token',
    ];
    $cfg['ai']['apiKeys'] = ['sk-test-key-12345678', 'sk-test-key-87654321'];
    $cfg['ai']['baseUrl'] = 'https://api.openai.com/v1';
    $cfg['ai']['model'] = 'gpt-4o-mini';
    $cfg['ai']['enabled'] = true;
    $cfg['ai']['rag']['enabled'] = true;
    $cfg['ai']['rag']['providers'] = [
        [
            'name' => 'siliconflow',
            'baseUrl' => 'https://api.siliconflow.cn/v1',
            'apiKey' => 'sk-silicon-12345678',
            'embeddingModel' => 'BAAI/bge-m3',
        ],
    ];
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
    $this->dataProp->setValue(null, $this->origData);
    $dynamicPath = Config::getDynamicConfigPath();
    if (is_file($dynamicPath)) {
        @unlink($dynamicPath);
    }
});

test('Config getMasked masks sensitive credentials', function () {
    $masked = Config::getMasked();

    expect($masked['admin']['token'])->toBe('******');
    expect($masked['ai']['apiKeys'][0])->toContain('****');
    expect($masked['ai']['apiKeys'][0])->not->toBe('sk-test-key-12345678');
    expect($masked['ai']['rag']['providers'][0]['apiKey'])->toContain('****');
    expect($masked['ai']['rag']['providers'][0]['apiKey'])->not->toBe('sk-silicon-12345678');
});

test('Config saveDynamic restores masked keys and updates settings', function () {
    $masked = Config::getMasked();

    // Modify a non-sensitive field while preserving masked apiKeys
    $updates = [
        'ai' => [
            'timeout' => 99,
            'apiKeys' => $masked['ai']['apiKeys'], // masked keys preserved
            'rag' => [
                'providers' => $masked['ai']['rag']['providers'], // masked provider apiKey preserved
            ],
        ],
        'storage' => [
            'storageTime' => 864000,
        ],
    ];

    Config::saveDynamic($updates);

    $current = Config::all();
    // Timeout and storageTime should be updated
    expect($current['ai']['timeout'])->toBe(99);
    expect($current['storage']['storageTime'])->toBe(864000);

    // Original secret values must be restored without being wiped by the mask
    expect($current['ai']['apiKeys'][0])->toBe('sk-test-key-12345678');
    expect($current['ai']['rag']['providers'][0]['apiKey'])->toBe('sk-silicon-12345678');

    // Dynamic config file must exist
    expect(is_file(Config::getDynamicConfigPath()))->toBeTrue();
});

test('Config resetDynamic removes dynamic overrides', function () {
    Config::saveDynamic([
        'storage' => ['storageTime' => 999999],
    ]);
    expect(Config::Get('storage')['storageTime'])->toBe(999999);

    Config::resetDynamic();
    expect(is_file(Config::getDynamicConfigPath()))->toBeFalse();
});

test('RagManager getStats and getBuildStatus work properly', function () {
    $stats = RagManager::getStats();

    expect($stats)->toHaveKeys(['dbPath', 'exists', 'fileSize', 'chunks', 'topics', 'buildStatus']);
    expect($stats['topics'])->toBeArray();

    $status = RagManager::getBuildStatus();
    expect($status)->toHaveKeys(['status', 'startedAt', 'finishedAt', 'result', 'error']);
});

test('AdminController config endpoints work properly', function () {
    $ctrl = new AdminController();
    $psr7Req = new Request('GET', '/v1/admin/config');
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $psr7Req);
    $reqRef = new ReflectionProperty($ctrl, 'request');
    $reqRef->setValue($ctrl, new \Hyperf\HttpServer\Request());

    // 1. GET config
    $res = $ctrl->getConfig();
    expect($res->getStatusCode())->toBe(200);
    $data = json_decode((string) $res->getBody(), true);
    expect($data['success'])->toBeTrue();
    expect($data['admin']['token'])->toBe('******');

    // 2. PUT config
    $putReq = (new Request('PUT', '/v1/admin/config'))
        ->withParsedBody([
            'ai' => [
                'timeout' => 88,
                'apiKeys' => $data['ai']['apiKeys'],
                'rag' => [
                    'providers' => $data['ai']['rag']['providers'],
                ],
            ],
        ]);
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $putReq);

    $updateRes = $ctrl->updateConfig();
    expect($updateRes->getStatusCode())->toBe(200);
    $updateData = json_decode((string) $updateRes->getBody(), true);
    expect($updateData['success'])->toBeTrue();
    expect(Config::Get('ai')['timeout'])->toBe(88);

    // 3. POST config/reset
    $resetReq = new Request('POST', '/v1/admin/config/reset');
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $resetReq);
    $resetRes = $ctrl->resetConfig();
    expect($resetRes->getStatusCode())->toBe(200);
    $resetData = json_decode((string) $resetRes->getBody(), true);
    expect($resetData['success'])->toBeTrue();
});

test('AdminController RAG endpoints work properly', function () {
    $ctrl = new AdminController();
    $psr7Req = new Request('GET', '/v1/admin/rag/stats');
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $psr7Req);
    $reqRef = new ReflectionProperty($ctrl, 'request');
    $reqRef->setValue($ctrl, new \Hyperf\HttpServer\Request());

    // 1. GET rag/stats
    $statsRes = $ctrl->getRagStats();
    expect($statsRes->getStatusCode())->toBe(200);
    $statsData = json_decode((string) $statsRes->getBody(), true);
    expect($statsData['success'])->toBeTrue();
    expect($statsData)->toHaveKeys(['dbPath', 'chunks', 'topics']);

    // 2. GET rag/build/status
    $statusRes = $ctrl->getRagBuildStatus();
    expect($statusRes->getStatusCode())->toBe(200);
    $statusData = json_decode((string) $statusRes->getBody(), true);
    expect($statusData['success'])->toBeTrue();
    expect($statusData['data']['status'])->toBeString();

    // 3. POST rag/search empty query throws 400
    $searchReq = (new Request('POST', '/v1/admin/rag/search'))->withParsedBody(['query' => '']);
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $searchReq);

    expect(fn() => $ctrl->searchRag())->toThrow(ApiError::class);
});
