<?php

use App\Rag\RagSearch;

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

test('LIKE fallback ranks title hits above body-only hits', function () {
    $pdo = $this->rag->getPdo();
    $pdo->exec("INSERT INTO docs(title, body, source) VALUES
        ('内存溢出专题', '详细讨论内存泄漏与 GC 调优', 'mem.md'),
        ('其他', '这里提到了内存，但正文无关紧要', 'other.md')");

    $results = $this->rag->search('内存', 5);
    expect(count($results))->toBeGreaterThanOrEqual(2);
    expect($results[0]['title'])->toBe('内存溢出专题');
});

test('search splits CJK multi-keyword queries with AND semantics', function () {
    $pdo = $this->rag->getPdo();
    $pdo->exec("INSERT INTO docs(title, body, source) VALUES
        ('更换皮肤与披风', '支持微软账号更换皮肤，通过 Mojang API 上传', 'skin.md'),
        ('账号管理', '管理微软账号与离线账号', 'account.md')");

    // Both terms appear only in skin.md
    $results = $this->rag->search('更换皮肤 微软账号', 5);
    expect($results)->toHaveCount(1);
    expect($results[0]['source'])->toBe('skin.md');
});

test('search split terms require all terms to match', function () {
    $pdo = $this->rag->getPdo();
    $pdo->exec("INSERT INTO docs(title, body, source) VALUES
        ('控制布局编辑器', '用于编辑控制布局', 'editor.md'),
        ('控件层', '承载控件的区域', 'layer.md')");

    // "控制布局" only in editor.md, "控件层" only in layer.md -> no doc has both
    $results = $this->rag->search('控制布局 控件层', 5);
    expect($results)->toBe([]);
});

test('splitTerms splits on whitespace and punctuation', function () {
    $ref = new ReflectionClass(RagSearch::class);
    $method = $ref->getMethod('splitTerms');

    expect($method->invoke(null, '更换皮肤，微软账号、离线账号'))->toBe(['更换皮肤', '微软账号', '离线账号']);
    expect($method->invoke(null, '  a  b  a  '))->toBe(['a', 'b']);
    expect($method->invoke(null, ''))->toBe([]);
    expect($method->invoke(null, 'Forge;Fabric:NeoForge'))->toBe(['Forge', 'Fabric', 'NeoForge']);
});

test('snippet returns whole body for short chunks', function () {
    $ref = new ReflectionClass(RagSearch::class);
    $method = $ref->getMethod('extractSnippet');

    $body = '这是一段很短的正文，直接整段返回。';
    expect($method->invoke(null, $body, ['短']))->toBe($body);
});

test('snippet centers on the first matching term for long bodies', function () {
    $ref = new ReflectionClass(RagSearch::class);
    $method = $ref->getMethod('extractSnippet');

    // 命中词在中后段，snippet 应围绕它，且以省略号开头
    $prefix = str_repeat('前置无关内容。', 60); // 420 字符
    $hit = '目标关键词';
    $suffix = str_repeat('后置无关内容。', 60);
    $body = $prefix . $hit . $suffix;

    $snippet = $method->invoke(null, $body, ['目标关键词']);

    expect($snippet)->toContain('目标关键词');
    expect($snippet)->toStartWith('…');
    expect(mb_strlen($snippet))->toBeLessThan(mb_strlen($body));
});

test('snippet returns leading fragment when term only matches title', function () {
    $ref = new ReflectionClass(RagSearch::class);
    $method = $ref->getMethod('extractSnippet');

    $body = str_repeat('正文里没有任何查询词。', 60); // 660 字符
    $snippet = $method->invoke(null, $body, ['标题词']);

    expect($snippet)->not->toContain('标题词');
    expect($snippet)->toEndWith('…');
});

test('search returns snippet alongside each result', function () {
    $results = $this->rag->search('OutOfMemoryError', 2);
    expect($results)->not->toBe([]);
    foreach ($results as $r) {
        expect($r)->toHaveKeys(['title', 'body', 'source', 'score', 'snippet']);
        expect($r['snippet'])->toBeString();
    }
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

    expect($chunks)->toHaveCount(3);
    expect($chunks[0]['title'])->toBe('a.md');
    expect($chunks[0]['body'])->toBe('preamble');
    expect($chunks[1]['title'])->toBe('标题一');
    expect($chunks[1]['body'])->toContain('内容一');
    expect($chunks[2]['title'])->toBe('标题二');
});

test('chunkMarkdown preserves H1 title and prefixes it to H2 chunks', function () {
    $chunks = RagSearch::chunkMarkdown('basic-concepts.md', "# 基本概念\n\n渲染基础介绍。\n\n## 缓冲构建器\n顶点数据\n\n## 绘制模式\n绘制方式\n");

    expect($chunks)->toHaveCount(3);
    expect($chunks[0]['title'])->toBe('基本概念');
    expect($chunks[0]['body'])->toContain('渲染基础介绍');
    expect($chunks[1]['title'])->toBe('基本概念 > 缓冲构建器');
    expect($chunks[2]['title'])->toBe('基本概念 > 绘制模式');
});

test('chunkMarkdown uses H1 as chunk title when no H2 exists', function () {
    $chunks = RagSearch::chunkMarkdown('creating-a-project.md', "# 创建项目\n\n正文内容...\n");

    expect($chunks)->toHaveCount(1);
    expect($chunks[0]['title'])->toBe('创建项目');
});

test('chunkMarkdown falls back to filename when no H1 or H2', function () {
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

test('topics groups docs by source directory', function () {
    $pdo = $this->rag->getPdo();
    $pdo->exec("INSERT INTO docs(title, body, source) VALUES
        ('A', '正文 a', 'zl_help/account.md'),
        ('B', '正文 b', 'zl_help/auth_server.md'),
        ('C', '正文 c', '日志分析/日志报错-内存溢出-KB-MEM-001-Java_heap_space.md')");

    $topics = $this->rag->topics();

    expect($topics)->toHaveCount(3);
    $dirs = array_column($topics, 'dir');
    expect($dirs)->toContain('(根目录)');
    expect($dirs)->toContain('zl_help');
    expect($dirs)->toContain('日志分析');

    foreach ($topics as $t) {
        expect($t)->toHaveKeys(['dir', 'count', 'files']);
        expect($t['count'])->toBeInt();
        expect($t['files'])->toBeArray();
    }

    $zl = array_values(array_filter($topics, fn($t) => $t['dir'] === 'zl_help'));
    expect($zl[0]['count'])->toBe(2);
});