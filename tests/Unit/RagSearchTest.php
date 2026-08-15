<?php

require_once __DIR__ . '/../../rag/RagSearch.php';

beforeEach(function () {
    $this->dbPath = CORE_PATH . '/tmp/rag_test_' . uniqid() . '.db';
    $this->rag = new RagSearch($this->dbPath);

    $pdo = $this->rag->getPdo();
    $pdo->exec(
        "INSERT INTO docs(title, body, source) VALUES
         ('OOM 排查', 'java.lang.OutOfMemoryError 堆内存不足导致崩溃，检查 -Xmx', 'oom.md'),
         ('启动慢', '服务器启动需要加载全部 mod 与数据包，建议用 SSD', 'startup.md'),
         ('崩溃日志分析', 'Caused by java.lang.OutOfMemoryError: Java heap space', 'crash.md'),
         ('中文短语测试', '模组兼容性问题导致游戏启动闪退', 'compat.md')"
    );
});

afterEach(function () {
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
});

test('search finds English tokens with BM25 ranking', function () {
    $results = $this->rag->search('OutOfMemoryError', 5);

    expect($results)->toHaveCount(2);
    $titles = array_column($results, 'title');
    expect($titles)->toContain('OOM 排查');
    expect($titles)->toContain('崩溃日志分析');
    expect(is_numeric($results[0]['score']))->toBeTrue();
});

test('search matches CJK phrases via LIKE fallback', function () {
    $results = $this->rag->search('服务器启动', 5);

    expect($results)->toHaveCount(1);
    expect($results[0]['title'])->toBe('启动慢');
    expect($results[0]['score'])->toBe('fallback');
});

test('search returns empty for unrelated queries', function () {
    expect($this->rag->search('zzznotexist', 5))->toBe([]);
    expect($this->rag->search('', 5))->toBe([]);
});

test('search caps k between 1 and 20', function () {
    // k=0 is clamped to 1, still returns at most one result
    expect(count($this->rag->search('java', 0)))->toBeLessThanOrEqual(1);
    $results = $this->rag->search('java', 100);
    expect(count($results))->toBeLessThanOrEqual(4);
});

test('search results include title, body, source', function () {
    $results = $this->rag->search('OutOfMemoryError', 1);
    $result = $results[0];

    expect($result)->toHaveKeys(['title', 'body', 'source', 'score']);
    expect($result['source'])->toBe('oom.md');
});

test('chunkMarkdown splits on ## headings', function () {
    $chunks = RagSearch::chunkMarkdown('a.md', "preamble\n\n## 标题一\n内容一\n\n## 标题二\n内容二\n");

    expect($chunks)->toHaveCount(2);
    expect($chunks[0]['title'])->toBe('标题一');
    expect($chunks[0]['body'])->toContain('内容一');
    expect($chunks[1]['title'])->toBe('标题二');
});

test('chunkMarkdown returns whole file when no headings', function () {
    $chunks = RagSearch::chunkMarkdown('a.md', 'no headings here');

    expect($chunks)->toHaveCount(1);
    expect($chunks[0]['title'])->toBe('a.md');
    expect($chunks[0]['body'])->toBe('no headings here');
});

test('chunkMarkdown returns empty for blank content', function () {
    expect(RagSearch::chunkMarkdown('a.md', '  '))->toBe([]);
});

test('buildIndex indexes markdown files and deletes stale rows', function () {
    $dir = CORE_PATH . '/tmp/rag_kb_' . uniqid();
    mkdir($dir, 0777, true);
    file_put_contents($dir . '/one.md', "## Alpha\n内容 a\n");
    file_put_contents($dir . '/two.txt', "## Beta\n内容 b\n");
    file_put_contents($dir . '/skip.jpg', 'not indexed');

    $result = $this->rag->buildIndex($dir);
    expect($result['files'])->toBe(2);
    expect($result['chunks'])->toBe(2);
    expect($this->rag->stats()['chunks'])->toBe(2);

    // Rebuild with fewer files must drop stale chunks
    unlink($dir . '/two.txt');
    $result = $this->rag->buildIndex($dir);
    expect($result['chunks'])->toBe(1);
    expect($this->rag->stats()['chunks'])->toBe(1);

    array_map('unlink', glob($dir . '/*'));
    rmdir($dir);
});