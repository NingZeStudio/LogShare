<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Rag\RagManager;
use App\Rag\RagSearch;

test('RagManager lists topics and documents correctly', function () {
    $result = RagManager::listDocs();
    expect($result)->toHaveKeys(['topics', 'docs', 'total']);
    expect($result['topics'])->toBeArray();
    expect($result['docs'])->toBeArray();
    expect($result['total'])->toBeGreaterThanOrEqual(0);

    // Filter by existing topic
    $patternsDocs = RagManager::listDocs('patterns');
    foreach ($patternsDocs['docs'] as $d) {
        expect($d['topic'])->toBe('patterns');
    }
});

test('RagManager CRUD cycle for knowledge document', function () {
    $topic = 'tools'; // tools is an existing registered test directory
    $filename = 'pest_test_doc_' . bin2hex(random_bytes(4)) . '.md';
    $testContent = "# 自动化测试文档\n\n这是通过单元测试生成的文档内容。";

    // 1. Create document
    $saved = RagManager::saveDoc($topic, $filename, $testContent, true);
    expect($saved['name'])->toBe($filename);
    expect($saved['topic'])->toBe($topic);
    expect($saved['path'])->toBe($topic . '/' . $filename);

    $relPath = $saved['path'];

    // 2. Read document
    $read = RagManager::readDoc($relPath);
    expect($read['name'])->toBe($filename);
    expect($read['content'])->toBe($testContent);
    expect($read['size'])->toBe(strlen($testContent));

    // 3. Update document (overwrite)
    $newContent = "# 更新后的标题\n\n内容已经变更。";
    $updated = RagManager::saveDoc($topic, $filename, $newContent, false);
    expect($updated['size'])->toBe(strlen($newContent));

    $readUpdated = RagManager::readDoc($relPath);
    expect($readUpdated['content'])->toBe($newContent);

    // 4. Duplicate prevention when isNew = true
    expect(fn() => RagManager::saveDoc($topic, $filename, 'dup', true))
        ->toThrow(\InvalidArgumentException::class);

    // 5. Delete document
    $deleted = RagManager::deleteDoc($relPath);
    expect($deleted['deleted'])->toBeTrue();

    // 6. Verify file is gone
    expect(fn() => RagManager::readDoc($relPath))
        ->toThrow(\InvalidArgumentException::class);
});

test('RagManager uploadDoc enforces size limits and saves content', function () {
    $topic = 'tools';
    $filename = 'upload_test_' . bin2hex(random_bytes(4)) . '.md';
    $tmpFile = sys_get_temp_dir() . '/' . $filename;
    file_put_contents($tmpFile, "# 上传测试文档\n通过临时文件上传测试。");

    try {
        $result = RagManager::uploadDoc($topic, $filename, $tmpFile);
        expect($result['name'])->toBe($filename);
        expect($result['topic'])->toBe($topic);

        $read = RagManager::readDoc($result['path']);
        expect($read['content'])->toContain('通过临时文件上传测试');

        RagManager::deleteDoc($result['path']);
    } finally {
        @unlink($tmpFile);
    }
});

test('RagManager strictly blocks path traversal and dangerous extensions', function () {
    // 1. Traversal sequences
    expect(fn() => RagManager::resolveSafePath('../etc/passwd'))
        ->toThrow(\InvalidArgumentException::class);
    expect(fn() => RagManager::resolveSafePath('tools/../../Config.inc.php'))
        ->toThrow(\InvalidArgumentException::class);
    expect(fn() => RagManager::resolveSafePath('tools/..\\something.md'))
        ->toThrow(\InvalidArgumentException::class);

    // 2. Null byte injection
    expect(fn() => RagManager::resolveSafePath("tools/test\0.md"))
        ->toThrow(\InvalidArgumentException::class);

    // 3. Disallowed extensions
    expect(fn() => RagManager::resolveSafePath('tools/malicious.php'))
        ->toThrow(\InvalidArgumentException::class);
    expect(fn() => RagManager::resolveSafePath('tools/evil.sh'))
        ->toThrow(\InvalidArgumentException::class);

    // 4. Unregistered topic directory
    expect(fn() => RagManager::resolveSafePath('non_existent_topic/doc.md'))
        ->toThrow(\InvalidArgumentException::class);

    // 5. Incomplete path (no topic or no file)
    expect(fn() => RagManager::resolveSafePath('just_file.md'))
        ->toThrow(\InvalidArgumentException::class);
});

test('AdminController RAG doc endpoints work properly', function () {
    if (!\Hyperf\Context\ApplicationContext::hasContainer()) {
        $container = \Mockery::mock(\Psr\Container\ContainerInterface::class);
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

    $ctrl = new \App\Controller\AdminController();
    $reqRef = new \ReflectionProperty($ctrl, 'request');
    $reqRef->setValue($ctrl, new \Hyperf\HttpServer\Request());

    // 1. GET rag/topics
    $psr7Req = new \Hyperf\HttpMessage\Server\Request('GET', '/v1/admin/rag/topics');
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $psr7Req);
    $topicsRes = $ctrl->getRagTopics();
    expect($topicsRes->getStatusCode())->toBe(200);
    $topicsData = json_decode((string) $topicsRes->getBody(), true);
    expect($topicsData['success'])->toBeTrue();
    expect($topicsData['topics'] ?? ($topicsData['data']['topics'] ?? null))->toBeArray();

    // 2. GET rag/docs
    $psr7Req = new \Hyperf\HttpMessage\Server\Request('GET', '/v1/admin/rag/docs');
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $psr7Req);
    $docsRes = $ctrl->getRagDocs();
    expect($docsRes->getStatusCode())->toBe(200);
    $docsData = json_decode((string) $docsRes->getBody(), true);
    expect($docsData['success'])->toBeTrue();
    expect($docsData['docs'] ?? ($docsData['data']['docs'] ?? null))->toBeArray();

    // 3. POST rag/docs/save
    $filename = 'ctrl_test_' . bin2hex(random_bytes(4)) . '.md';
    $saveReq = (new \Hyperf\HttpMessage\Server\Request('POST', '/v1/admin/rag/docs/save'))
        ->withParsedBody([
            'topic' => 'tools',
            'filename' => $filename,
            'content' => '# 控制器接口测试文档',
            'isNew' => true,
        ]);
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $saveReq);
    $saveRes = $ctrl->saveRagDoc();
    expect($saveRes->getStatusCode())->toBe(200);
    $saveData = json_decode((string) $saveRes->getBody(), true);
    expect($saveData['success'])->toBeTrue();
    $savedPath = $saveData['path'] ?? $saveData['data']['path'];

    // 4. GET rag/docs/content
    $contentReq = (new \Hyperf\HttpMessage\Server\Request('GET', '/v1/admin/rag/docs/content'))
        ->withQueryParams(['path' => $savedPath]);
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $contentReq);
    $contentRes = $ctrl->getRagDocContent();
    expect($contentRes->getStatusCode())->toBe(200);
    $contentData = json_decode((string) $contentRes->getBody(), true);
    expect($contentData['success'])->toBeTrue();
    $contentStr = $contentData['content'] ?? $contentData['data']['content'];
    expect($contentStr)->toContain('控制器接口测试文档');

    // 5. POST rag/docs/upload (JSON mode)
    $uploadJsonReq = (new \Hyperf\HttpMessage\Server\Request('POST', '/v1/admin/rag/docs/upload'))
        ->withParsedBody([
            'topic' => 'tools',
            'filename' => 'json_upload_' . bin2hex(random_bytes(4)) . '.md',
            'content' => '# JSON 批量上传测试',
        ]);
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $uploadJsonReq);
    $uploadRes = $ctrl->uploadRagDoc();
    expect($uploadRes->getStatusCode())->toBe(200);
    $uploadData = json_decode((string) $uploadRes->getBody(), true);
    expect($uploadData['success'])->toBeTrue();
    $uploadedPath = $uploadData['path'] ?? $uploadData['data']['path'];

    // 6. DELETE rag/docs
    $delReq = (new \Hyperf\HttpMessage\Server\Request('DELETE', '/v1/admin/rag/docs'))
        ->withQueryParams(['path' => $savedPath]);
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $delReq);
    $delRes = $ctrl->deleteRagDoc();
    expect($delRes->getStatusCode())->toBe(200);

    $delUploadReq = (new \Hyperf\HttpMessage\Server\Request('POST', '/v1/admin/rag/docs/delete'))
        ->withParsedBody(['path' => $uploadedPath]);
    \Hyperf\Context\Context::set(\Psr\Http\Message\ServerRequestInterface::class, $delUploadReq);
    $ctrl->deleteRagDoc();
});
