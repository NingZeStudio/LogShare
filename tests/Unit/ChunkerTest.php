<?php

use App\Rag\Chunk;
use App\Rag\Chunker;
use App\Rag\ChunkStrategy;
use App\Rag\RagSearch;

/* ─── 策略枚举 ───────────────────────────────────────────── */

test('ChunkStrategy config mapping with fallback', function () {
    expect(ChunkStrategy::fromConfig('heading'))->toBe(ChunkStrategy::HEADING_ONLY)
        ->and(ChunkStrategy::fromConfig('sliding'))->toBe(ChunkStrategy::SLIDING_WINDOW)
        ->and(ChunkStrategy::fromConfig('token'))->toBe(ChunkStrategy::TOKEN_AWARE)
        ->and(ChunkStrategy::fromConfig('HYBRID'))->toBe(ChunkStrategy::HYBRID)
        ->and(ChunkStrategy::fromConfig(' hybrid '))->toBe(ChunkStrategy::HYBRID)
        ->and(ChunkStrategy::fromConfig(null))->toBe(ChunkStrategy::HEADING_ONLY)
        ->and(ChunkStrategy::fromConfig('nonsense'))->toBe(ChunkStrategy::HEADING_ONLY)
        ->and(ChunkStrategy::fromConfig(['a']))->toBe(ChunkStrategy::HEADING_ONLY);
});

/* ─── heading 与旧实现回归等价 ───────────────────────────── */

test('byHeading output equals legacy chunkMarkdown shape', function () {
    $samples = [
        ["preamble\n\n## 标题一\n内容一\n\n## 标题二\n内容二\n", 'a.md'],
        ["# 基本概念\n\n渲染基础介绍。\n\n## 缓冲构建器\n顶点数据\n\n## 绘制模式\n绘制方式\n", 'basic-concepts.md'],
        ["# 创建项目\n\n正文内容...\n", 'creating-a-project.md'],
        ['no headings here', 'a.md'],
        ['   ', 'a.md'],
        ["## 尾随空白标题 \n正文\n", 't.md'],
    ];

    foreach ($samples as [$content, $source]) {
        $legacy = RagSearch::chunkMarkdown($source, $content);
        $new = array_map(fn(Chunk $c): array => $c->toLegacyArray(), Chunker::byHeading($source, $content));
        expect($new)->toBe($legacy, "mismatch for source {$source}");
    }
});

test('byHeading records offsets that locate body within trimmed content', function () {
    $content = "# 标题\n\n引言段落。\n\n## A\nAAA 正文\n";
    $chunks = Chunker::byHeading('x.md', $content);

    expect($chunks)->toHaveCount(2);
    foreach ($chunks as $chunk) {
        // 偏移为字节语义（mb_strcut 安全）：substr 还原必须逐字节一致
        $slice = substr(trim($content), $chunk->startOffset, $chunk->endOffset - $chunk->startOffset);
        expect($slice)->toBe($chunk->body, 'offsets must slice back to the exact body');
        expect($chunk->tokenCount)->toBe(Chunker::estimateTokens($chunk->body));
    }
});

/* ─── 滑动窗口 ───────────────────────────────────────────── */

test('sliding window chunks with overlap and covers all content', function () {
    $content = str_repeat('甲乙丙丁。', 100); // 500 字符
    $chunks = Chunker::bySlidingWindow($content, chunkSize: 100, overlap: 20, source: 's.txt', title: 'S');

    // 起点 0,80,160,240,320,400 → 6 窗
    expect($chunks)->toHaveCount(6);
    foreach ($chunks as $i => $chunk) {
        // 窗口间保留 20 字符重叠：起点差恒为 step=80 字符的字节数
        expect(mb_strlen($chunk->body))->toBeLessThanOrEqual(100);
        expect($chunk->title)->toBe('S');
        // 字节偏移可还原窗口
        expect(substr($content, $chunk->startOffset, $chunk->endOffset - $chunk->startOffset))->toBe($chunk->body);
        if ($i > 0) {
            expect($chunk->startOffset)->toBe($chunks[$i - 1]->startOffset + strlen(mb_substr($content, ($i - 1) * 80, 80)));
        }
    }
    // 首尾覆盖完整内容
    expect($chunks[0]->startOffset)->toBe(0);
    expect($chunks[count($chunks) - 1]->endOffset)->toBe(strlen($content));
});

test('sliding window on short content yields single chunk', function () {
    $chunks = Chunker::bySlidingWindow('短文本', chunkSize: 100, overlap: 20, source: 's.md');
    expect($chunks)->toHaveCount(1);
    expect($chunks[0]->body)->toBe('短文本');
});

/* ─── token-aware ────────────────────────────────────────── */

test('token-aware splits on sentence bounds within maxTokens', function () {
    // 每句 20 个 CJK 字符 + 标点 ≈ 21 token；maxTokens=50 → 每块约 2 句
    $sentence = str_repeat('中', 20) . '。';
    $content = str_repeat($sentence, 10);
    $chunks = Chunker::byToken($content, maxTokens: 50, overlapTokens: 0, source: 't.md', title: 'T');

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        expect($chunk->tokenCount)->toBeLessThanOrEqual(50 + 21); // 单句超限时允许一次溢出
    }
    // 无重叠时拼接还原原文
    expect(implode('', array_column($chunks, 'body')))->toBe($content);
});

test('token-aware with overlap repeats tail sentences across boundary', function () {
    $sentence = str_repeat('词', 10) . '。';
    $content = str_repeat($sentence, 6);
    $noOverlap = Chunker::byToken($content, maxTokens: 25, overlapTokens: 0, source: 't.md');
    $withOverlap = Chunker::byToken($content, maxTokens: 25, overlapTokens: 15, source: 't.md');

    expect(count($noOverlap))->toBe(3);
    // 带重叠的块更少或持平（尾部句并入下一块），且首块不变
    expect($withOverlap[0]->body)->toBe($noOverlap[0]->body);
    // 重叠块的 body 起点 < 前一块 body 终点（字节偏移连续性）
    $found = false;
    for ($i = 1; $i < count($withOverlap); $i++) {
        if ($withOverlap[$i]->startOffset < $withOverlap[$i - 1]->endOffset) {
            $found = true;
        }
    }
    expect($found)->toBeTrue('overlap windows must share boundary sentences');
});

/* ─── HYBRID parent-child ────────────────────────────────── */

test('hybrid re-splits oversized heading chunks and links parent rowid index', function () {
    $long = str_repeat('长', 1200) . '。'; // ≈1201 token > 1024 阈值
    $content = "# 文档\n\n## 短节\n简短内容\n\n## 长节\n" . $long . "\n\n## 尾节\n收尾\n";

    $chunks = Chunker::chunk('h.md', $content, ChunkStrategy::HYBRID);

    $titles = array_column($chunks, 'title');
    expect($titles)->toContain('文档 > 短节');
    expect($titles)->toContain('文档 > 尾节');

    $parentIdx = null;
    $childCount = 0;
    foreach ($chunks as $i => $c) {
        if ($c->parentId === null) {
            if ($c->title === '文档 > 长节') {
                $parentIdx = $i;
            }
            continue;
        }
        $childCount++;
        expect($c->parentId)->toBe($parentIdx, 'children reference the oversized parent position in output');
        expect($c->title)->toBe('文档 > 长节');
        // 子块 body 必须是父块 body 的精确字节子串
        expect(substr($chunks[$parentIdx]->body, $c->startOffset - $chunks[$parentIdx]->startOffset, $c->endOffset - $c->startOffset))
            ->toBe($c->body);
    }
    expect($childCount)->toBeGreaterThan(1, 'oversized chunk must yield multiple children');
    // 父块保留（parent 提供完整上下文）
    expect($chunks[$parentIdx]->body)->toBe(trim($long));
});

test('hybrid equals heading-only when no chunk exceeds threshold', function () {
    $content = "# T\n\n## A\naaa\n\n## B\nbbb\n";
    $hybrid = Chunker::chunk('h.md', $content, ChunkStrategy::HYBRID);
    $heading = Chunker::chunk('h.md', $content, ChunkStrategy::HEADING_ONLY);

    // Chunk 是对象，按字段结构比较而非实例身份
    expect(array_map(fn(Chunk $c): array => $c->toLegacyArray() + ['parentId' => $c->parentId], $hybrid))
        ->toBe(array_map(fn(Chunk $c): array => $c->toLegacyArray() + ['parentId' => $c->parentId], $heading));
});

/* ─── token 估算 ─────────────────────────────────────────── */

test('estimateTokens heuristic bounds', function () {
    expect(Chunker::estimateTokens(''))->toBe(0);
    // 纯 ASCII：4 字节 1 token
    expect(Chunker::estimateTokens(str_repeat('a', 400)))->toBe(101);
    // 纯 CJK：1 字符 1 token
    expect(Chunker::estimateTokens(str_repeat('中', 100)))->toBe(101);
    // 单调性：更长文本 token 不减
    expect(Chunker::estimateTokens('一二三四五六七八九十大于一二三四五六七八九十大'))
        ->toBeGreaterThan(Chunker::estimateTokens('一二三'));
});

/* ─── buildIndex 元数据落库 ──────────────────────────────── */

function ragBuildFixture(array $files): RagSearch
{
    $dbPath = CORE_PATH . '/tmp/rag_chunk_' . uniqid() . '.db';
    $dir = CORE_PATH . '/tmp/rag_kb_' . uniqid();
    mkdir($dir, 0777, true);
    foreach ($files as $name => $body) {
        file_put_contents($dir . '/' . $name, $body);
    }

    $rag = new RagSearch($dbPath);
    $rag->buildIndex($dir);

    // 记录临时路径供 afterEach 清理（借用全局数组）
    $GLOBALS['ragChunkCleanups'][] = [$dbPath, $dir];
    return $rag;
}

beforeEach(function () {
    $GLOBALS['ragChunkCleanups'] = [];
});
afterEach(function () {
    foreach ($GLOBALS['ragChunkCleanups'] as [$db, $dir]) {
        if (file_exists($db)) {
            unlink($db);
        }
        array_map('unlink', glob($dir . '/*') ?: []);
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
});

test('buildIndex persists chunk_meta with token counts and mtimes', function () {
    $rag = ragBuildFixture(['a.md' => "## Alpha\n内容 a\n", 'b.md' => "## Beta\n内容 b 更长一点\n"]);
    $rows = $rag->getPdo()->query('SELECT m.rowid, m.token_count, m.parent_rowid, m.source_mtime, d.source FROM chunk_meta m JOIN docs d ON d.rowid = m.rowid ORDER BY m.rowid')->fetchAll();

    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect((int) $row['token_count'])->toBeGreaterThan(0);
        expect($row['parent_rowid'])->toBeNull();
        expect((int) $row['source_mtime'])->toBeGreaterThan(1000000);
    }
});

test('buildIndex resolves hybrid parent_rowid to real rowids', function () {
    $long = str_repeat('长', 1200) . '。';
    // 配置 hybrid：通过 \App\Config 单例注入
    $cfgRef = new ReflectionClass(\App\Config::class);
    $dataProp = $cfgRef->getProperty('data');
    $orig = $dataProp->getValue();
    $data = $orig;
    $data['ai']['rag']['chunker'] = 'hybrid';
    $data['ai']['rag']['enabled'] = false;
    $dataProp->setValue(null, $data);

    $rag = ragBuildFixture(['h.md' => "# T\n\n## 长节\n" . $long . "\n"]);
    $dataProp->setValue(null, $orig);

    $rows = $rag->getPdo()->query('SELECT m.rowid, m.parent_rowid FROM chunk_meta m ORDER BY m.rowid')->fetchAll();
    expect(count($rows))->toBeGreaterThan(2, 'parent + children indexed');

    $parentRowids = [];
    $childLinked = 0;
    foreach ($rows as $r) {
        if ($r['parent_rowid'] === null) {
            $parentRowids[] = (int) $r['rowid'];
        } else {
            $childLinked++;
            expect(in_array((int) $r['parent_rowid'], $parentRowids, true) || $parentRowids === [])->toBeTrue();
        }
    }
    expect($childLinked)->toBeGreaterThan(0);
    // 父行 rowid 必须先于其子行（插入顺序保证 parentId 已解析）
    expect($parentRowids[0])->toBeLessThan($rows[count($rows) - 1]['rowid']);
});

test('default heading build output is row-for-row identical to legacy chunker', function () {
    $content = "# 基本概念\n\n介绍。\n\n## 缓冲构建器\n顶点数据\n\n## 绘制模式\n绘制方式\n";
    $rag = ragBuildFixture(['basic.md' => $content]);
    $docs = $rag->getPdo()->query('SELECT title, body, source FROM docs ORDER BY rowid')->fetchAll();

    expect($docs)->toHaveCount(3);
    expect($docs[0]['title'])->toBe('基本概念');
    expect($docs[1]['title'])->toBe('基本概念 > 缓冲构建器');
    expect($docs[2]['title'])->toBe('基本概念 > 绘制模式');
    expect(array_column($docs, 'body'))->toBe(['介绍。', '顶点数据', '绘制方式']);
});
