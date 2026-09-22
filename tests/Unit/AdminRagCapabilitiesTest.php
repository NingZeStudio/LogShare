<?php

declare(strict_types=1);

use App\Config;
use App\Controller\AdminController;
use App\Rag\RagSearch;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\HttpMessage\Server\Request as PsrRequest;
use Hyperf\HttpServer\Request as HyperfRequest;

function arcController(): AdminController
{
    if (!ApplicationContext::hasContainer()) {
        $container = Mockery::mock(\Psr\Container\ContainerInterface::class);
        $container->shouldReceive('has')->andReturn(true);
        $container->shouldReceive('get')->andReturnUsing(function ($class) {
            if ($class === \Hyperf\HttpServer\Contract\RequestInterface::class) {
                return new HyperfRequest();
            }
            if ($class === \Hyperf\HttpServer\Response::class) {
                return new \Hyperf\HttpServer\Response();
            }
            return null;
        });
        ApplicationContext::setContainer($container);
    }
    $ctrl = new AdminController();
    (new ReflectionProperty($ctrl, 'request'))->setValue($ctrl, new HyperfRequest());
    return $ctrl;
}

function arcSetContextRequest(PsrRequest $req): void
{
    Context::set(\Psr\Http\Message\ServerRequestInterface::class, $req);
}

function arcConfig(array $patch): void
{
    $dataProp = (new ReflectionClass(Config::class))->getProperty('data');
    if (!isset($GLOBALS['arcOrig'])) {
        $GLOBALS['arcOrig'] = [$dataProp, $dataProp->getValue()];
    }
    $data = $dataProp->getValue() ?? [];
    foreach ($patch as $path => $value) {
        $ref = &$data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($ref[$key] ?? null)) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
        $ref = $value;
        unset($ref);
    }
    $dataProp->setValue(null, $data);
}

beforeEach(function () {
    arcConfig(['admin' => ['enabled' => true, 'token' => 'test-admin-secret-token']]);
});

afterEach(function () {
    if (!empty($GLOBALS['arcOrig'])) {
        [$dataProp, $orig] = $GLOBALS['arcOrig'];
        $dataProp->setValue(null, $orig);
        unset($GLOBALS['arcOrig']);
    }
    $dynamicPath = Config::getDynamicConfigPath();
    if (is_file($dynamicPath)) {
        @unlink($dynamicPath);
        clearstatcache(true, $dynamicPath);
    }
    $ref = new ReflectionClass(Config::class);
    $ref->getProperty('dynamicMtime')->setValue(null, 0);
    $ref->getProperty('dynamicSize')->setValue(null, -1);
    $ref->getProperty('dynamicVersion')->setValue(null, '');
    if (array_key_exists('arcEnv', $GLOBALS)) {
        $GLOBALS['arcEnv'] === false ? putenv('RAG_DB_PATH') : putenv('RAG_DB_PATH=' . $GLOBALS['arcEnv']);
        unset($GLOBALS['arcEnv']);
    }
    if (!empty($GLOBALS['arcDb']) && file_exists($GLOBALS['arcDb'])) {
        unlink($GLOBALS['arcDb']);
    }
    unset($GLOBALS['arcDb']);
});

test('GET rag/config returns the capability switch snapshot', function () {
    arcConfig(['ai.rag' => [
        'chunker' => 'hybrid',
        'rerank' => ['enabled' => true, 'maxCandidates' => 12],
        'queryRewrite' => ['enabled' => true],
        'incrementalBuild' => true,
        'semanticCache' => false,
        'telemetry' => ['enabled' => true, 'slowMs' => 250],
    ]]);
    $ctrl = arcController();
    arcSetContextRequest(new PsrRequest('GET', '/v1/admin/rag/config'));

    $res = $ctrl->getRagConfig();
    expect($res->getStatusCode())->toBe(200);
    // respondSuccess 对无保留键的关联数组按顶层展开（非 data 包裹）
    $data = json_decode((string) $res->getBody(), true);
    expect($data['chunker'])->toBe('hybrid');
    expect($data['rerank'])->toBe(['enabled' => true, 'maxCandidates' => 12]);
    expect($data['incrementalBuild'])->toBeTrue();
    expect($data['semanticCache'])->toBeFalse();
    expect($data['telemetry']['slowMs'])->toBe(250);
});

test('PUT rag/config validates and persists known switches only', function () {
    arcConfig(['ai.rag.chunker' => 'heading']);
    $ctrl = arcController();

    // 非法 chunker → 422
    arcSetContextRequest((new PsrRequest('PUT', '/v1/admin/rag/config'))
        ->withParsedBody(['chunker' => 'nope']));
    expect(fn() => $ctrl->updateRagConfig())->toThrow(App\ApiError::class, 'chunker');

    // 空/无识别字段 → 400
    arcSetContextRequest((new PsrRequest('PUT', '/v1/admin/rag/config'))
        ->withParsedBody(['unknown' => 1]));
    expect(fn() => $ctrl->updateRagConfig())->toThrow(App\ApiError::class);

    // 合法更新 → 生效并回读
    arcSetContextRequest((new PsrRequest('PUT', '/v1/admin/rag/config'))
        ->withParsedBody(['chunker' => 'HYBRID', 'rerank' => ['enabled' => true, 'maxCandidates' => 999], 'incrementalBuild' => true]));
    $res = $ctrl->updateRagConfig();
    expect($res->getStatusCode())->toBe(200);
    $data = json_decode((string) $res->getBody(), true);
    expect($data['chunker'])->toBe('hybrid', '大小写归一并落库');
    expect($data['rerank']['enabled'])->toBeTrue();
    expect($data['rerank']['maxCandidates'])->toBe(50, '越界裁剪到上限 50');
    expect($data['incrementalBuild'])->toBeTrue();
    expect(Config::all()['ai']['rag']['chunker'])->toBe('hybrid', 'saveDynamic 热生效');
});

test('GET rag/build/stale returns 409 when index missing', function () {
    $GLOBALS['arcEnv'] = getenv('RAG_DB_PATH');
    putenv('RAG_DB_PATH=' . CORE_PATH . '/tmp/arc_missing_' . uniqid() . '.db');
    $ctrl = arcController();
    arcSetContextRequest(new PsrRequest('GET', '/v1/admin/rag/build/stale'));

    expect(fn() => $ctrl->getRagStaleFiles())->toThrow(App\ApiError::class, 'Index not built');
});

test('GET rag/build/stale lists diffs when an index exists', function () {
    if (!is_dir(App\Rag\RagManager::getKnowledgeDir())) {
        test()->skip('rag/knowledge not present in this checkout');
    }
    $dbPath = CORE_PATH . '/tmp/arc_stale_' . uniqid() . '.db';
    $GLOBALS['arcDb'] = $dbPath;
    new RagSearch($dbPath); // 空索引 → 全部 KB 文件视为 changed
    $GLOBALS['arcEnv'] = getenv('RAG_DB_PATH');
    putenv('RAG_DB_PATH=' . $dbPath);

    $ctrl = arcController();
    arcSetContextRequest(new PsrRequest('GET', '/v1/admin/rag/build/stale'));
    $res = $ctrl->getRagStaleFiles();
    expect($res->getStatusCode())->toBe(200);
    $data = json_decode((string) $res->getBody(), true);
    expect($data)->toHaveKeys(['changed', 'missing', 'unchanged']);
    expect($data['changed'])->toBeArray();
});

test('POST rag/build/incremental triggers a build and returns status', function () {
    if (!is_dir(App\Rag\RagManager::getKnowledgeDir())) {
        test()->skip('rag/knowledge not present in this checkout');
    }
    $dbPath = CORE_PATH . '/tmp/arc_inc_' . uniqid() . '.db';
    $GLOBALS['arcDb'] = $dbPath;
    new RagSearch($dbPath);
    $GLOBALS['arcEnv'] = getenv('RAG_DB_PATH');
    putenv('RAG_DB_PATH=' . $dbPath);

    arcConfig(['ai.rag.enabled' => false]); // 不触网

    // 备份构建状态文件
    $statusFile = CORE_PATH . '/runtime/rag_build_status.json';
    $backup = is_file($statusFile) ? (string) file_get_contents($statusFile) : null;

    $ctrl = arcController();
    arcSetContextRequest(new PsrRequest('POST', '/v1/admin/rag/build/incremental'));
    try {
        $res = $ctrl->triggerRagIncrementalBuild();
        expect($res->getStatusCode())->toBe(200);
        $data = json_decode((string) $res->getBody(), true)['data'] ?? [];
        expect($data['success'])->toBeTrue();
    } finally {
        $backup === null ? @unlink($statusFile) : file_put_contents($statusFile, $backup);
        (new ReflectionProperty(App\Rag\RagManager::class, 'memoryStatus'))
            ->setValue(null, $backup === null ? null : json_decode($backup, true));
    }
});

test('GET rag/telemetry reports availability and validates date', function () {
    $ctrl = arcController();

    arcSetContextRequest((new PsrRequest('GET', '/v1/admin/rag/telemetry'))
        ->withQueryParams(['date' => '2026-01-01']));
    $res = $ctrl->getRagTelemetry();
    expect($res->getStatusCode())->toBe(200);
    $data = json_decode((string) $res->getBody(), true);
    expect($data['date'])->toBe('2026-01-01');
    expect($data)->toHaveKeys(['summary', 'available', 'slowQueries']);

    // 非法日期格式 → 400
    arcSetContextRequest((new PsrRequest('GET', '/v1/admin/rag/telemetry'))
        ->withQueryParams(['date' => 'bad-date']));
    expect(fn() => $ctrl->getRagTelemetry())->toThrow(App\ApiError::class, 'Y-m-d');
});
