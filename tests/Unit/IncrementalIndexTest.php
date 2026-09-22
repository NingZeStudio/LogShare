<?php

declare(strict_types=1);

use App\Rag\RagManager;
use App\Rag\RagSearch;

/* ─── 测试脚手架 ─────────────────────────────────────────── */

/** 在当前 App\Config 单例数据上打补丁（点号路径），afterEach 统一还原 */
function incSetConfig(array $patch): void
{
    $dataProp = (new ReflectionClass(\App\Config::class))->getProperty('data');
    if (!isset($GLOBALS['incOrigConfig'])) {
        $GLOBALS['incOrigConfig'] = [$dataProp, $dataProp->getValue()];
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

afterEach(function () {
    if (!empty($GLOBALS['incOrigConfig'])) {
        [$dataProp, $orig] = $GLOBALS['incOrigConfig'];
        $dataProp->setValue(null, $orig);
        unset($GLOBALS['incOrigConfig']);
    }
    if (!empty($GLOBALS['incDb']) && file_exists($GLOBALS['incDb'])) {
        unlink($GLOBALS['incDb']);
    }
    unset($GLOBALS['incDb']);
    if (array_key_exists('incEnv', $GLOBALS)) {
        if ($GLOBALS['incEnv'] === false) {
            putenv('RAG_DB_PATH');
        } else {
            putenv('RAG_DB_PATH=' . $GLOBALS['incEnv']);
        }
        unset($GLOBALS['incEnv']);
    }
});

function incDbPath(): string
{
    $path = CORE_PATH . '/tmp/rag_inc_' . uniqid() . '.db';
    $GLOBALS['incDb'] = $path;
    return $path;
}

/** 手工插入一个 chunk 行并登记 chunk_meta，返回 rowid */
function incInsertChunk(RagSearch $rag, string $title, string $body, string $source, int $mtime, ?int $parentRowid = null, int $tokenCount = 0): int
{
    $pdo = $rag->getPdo();
    $pdo->prepare('INSERT INTO docs(title, body, source) VALUES (?, ?, ?)')->execute([$title, $body, $source]);
    $rowid = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO chunk_meta(rowid, token_count, parent_rowid, start_offset, end_offset, source_mtime)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$rowid, $tokenCount, $parentRowid, 0, strlen($body), $mtime]);
    return $rowid;
}

/* ─── RagSearch：indexedMtimes / reindexSource / forgetSource ─── */

test('indexedMtimes returns max source_mtime per file', function () {
    $rag = new RagSearch(incDbPath());
    incInsertChunk($rag, 'T1', 'body one', 'tools/a.md', 1000);
    incInsertChunk($rag, 'T2', 'body two', 'tools/a.md', 2500);
    incInsertChunk($rag, 'T3', 'body three', 'patterns/b.md', 700);

    $mtimes = $rag->indexedMtimes();
    ksort($mtimes);
    expect($mtimes)->toBe(['patterns/b.md' => 700, 'tools/a.md' => 2500]);
});

test('reindexSource replaces old chunks and refreshes mtime', function () {
    $rag = new RagSearch(incDbPath());
    incInsertChunk($rag, '旧块一', 'old content one', 'tools/a.md', 1000);
    incInsertChunk($rag, '旧块二', 'old content two', 'tools/a.md', 1000);
    incInsertChunk($rag, '别的文件', 'keep me', 'patterns/b.md', 500);

    $content = "# 新文档\n\n## 小节一\n新内容 alpha\n\n## 小节二\n新内容 beta\n";
    $r = $rag->reindexSource('tools/a.md', $content, null, 4321);

    expect($r['chunks'])->toBe(2, '按标题切成 preamble? no: 两个 H2 小节');
    expect($r['embedded'])->toBe(0);

    $rows = $rag->getPdo()->query("SELECT title FROM docs WHERE source = 'tools/a.md' ORDER BY rowid")->fetchAll(PDO::FETCH_COLUMN);
    expect($rows)->toBe(['新文档 > 小节一', '新文档 > 小节二']);

    // 其他文件不受影响
    expect((int) $rag->getPdo()->query("SELECT count(*) FROM docs WHERE source = 'patterns/b.md'")->fetchColumn())->toBe(1);
    $mtimes = $rag->indexedMtimes();
    ksort($mtimes);
    expect($mtimes)->toBe(['patterns/b.md' => 500, 'tools/a.md' => 4321]);
});

test('reindexSource prunes orphan embeddings and parent links', function () {
    $rag = new RagSearch(incDbPath());
    $parent = incInsertChunk($rag, '父块', 'parent body', 'tools/a.md', 1000, null, 10);
    $child = incInsertChunk($rag, '子块', 'child body', 'tools/a.md', 1000, $parent, 5);
    $other = incInsertChunk($rag, '他文件', 'other', 'patterns/b.md', 1000);

    $embed = $rag->getPdo()->prepare('INSERT INTO doc_embeddings(rowid, vec) VALUES (?, ?)');
    foreach ([$parent, $child, $other] as $rid) {
        $embed->execute([$rid, "\x00\x00\x80?"]);
    }
    expect((int) $rag->getPdo()->query('SELECT count(*) FROM doc_embeddings')->fetchColumn())->toBe(3);

    $rag->reindexSource('tools/a.md', "# 重写\n\n只剩一节\n", null, 2000);

    // 旧父/子块的向量与元数据被清；other 的保留
    expect((int) $rag->getPdo()->query('SELECT count(*) FROM doc_embeddings')->fetchColumn())->toBe(1);
    expect((int) $rag->getPdo()->query('SELECT count(*) FROM chunk_meta')->fetchColumn())->toBe(2, '1 旧(other) + 1 新');
    // 新文件的父块自引用完整：不存在 parent_rowid 指向已删 rowid 的孤儿
    $orphans = (int) $rag->getPdo()->query(
        'SELECT count(*) FROM chunk_meta WHERE parent_rowid IS NOT NULL AND parent_rowid NOT IN (SELECT rowid FROM docs)'
    )->fetchColumn();
    expect($orphans)->toBe(0);
});

test('forgetSource removes chunks, embeddings and meta for that file', function () {
    $rag = new RagSearch(incDbPath());
    $gone = incInsertChunk($rag, '待删', 'doomed body', 'tools/dead.md', 10);
    incInsertChunk($rag, '保留', 'kept body', 'tools/keep.md', 10);
    $rag->getPdo()->prepare('INSERT INTO doc_embeddings(rowid, vec) VALUES (?, ?)')->execute([$gone, "\x00\x00\x80?"]);

    $n = $rag->forgetSource('tools/dead.md');

    expect($n)->toBe(1);
    expect($rag->getPdo()->query("SELECT source FROM docs")->fetchAll(PDO::FETCH_COLUMN))->toBe(['tools/keep.md']);
    expect((int) $rag->getPdo()->query('SELECT count(*) FROM doc_embeddings')->fetchColumn())->toBe(0);
    expect($rag->indexedMtimes())->toBe(['tools/keep.md' => 10]);
});

/* ─── RagSearch::incrementalBuildEnabled 开关 ────────────── */

test('incrementalBuildEnabled defaults to false and follows config', function () {
    incSetConfig(['ai.rag.incrementalBuild' => false]);
    expect(RagSearch::incrementalBuildEnabled())->toBeFalse();

    incSetConfig(['ai.rag.incrementalBuild' => true]);
    expect(RagSearch::incrementalBuildEnabled())->toBeTrue();
});

/* ─── RagManager::getStaleFiles ──────────────────────────── */

/** 从真实知识库列出全部可索引文件（rel → mtime） */
function incListKbFiles(): array
{
    $kbDir = RagManager::getKnowledgeDir();
    if (!is_dir($kbDir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kbDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || !in_array(strtolower($f->getExtension()), RagManager::ALLOWED_EXTENSIONS, true)) {
            continue;
        }
        $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen(rtrim($kbDir, '/\\')))), '/');
        $out[$rel] = (int) $f->getMTime();
    }
    return $out;
}

test('getStaleFiles throws when no index exists', function () {
    $GLOBALS['incEnv'] = getenv('RAG_DB_PATH');
    putenv('RAG_DB_PATH=' . CORE_PATH . '/tmp/rag_inc_missing_' . uniqid() . '.db');

    expect(fn() => RagManager::getStaleFiles())->toThrow(RuntimeException::class);
});

test('getStaleFiles classifies changed / missing / unchanged by mtime', function () {
    $kb = incListKbFiles();
    if (count($kb) < 2) {
        test()->skip('rag/knowledge not present in this checkout');
    }
    $sources = array_keys($kb);
    [$fresh, $stale] = $sources;

    $dbPath = incDbPath();
    $rag = new RagSearch($dbPath);
    foreach ($kb as $rel => $mtime) {
        incInsertChunk($rag, 'F', 'body', $rel, $mtime);
    }
    // 覆写两个样本：一个 mtime 不符（changed），另加一个索引里的幽灵文件（missing）
    $rag->getPdo()->prepare('UPDATE chunk_meta SET source_mtime = ? WHERE rowid IN (SELECT rowid FROM docs WHERE source = ?)')
        ->execute([$kb[$stale] + 10, $stale]);
    incInsertChunk($rag, 'D', 'ghost body', 'tools/__gone__.md', 1);

    $GLOBALS['incEnv'] = getenv('RAG_DB_PATH');
    putenv('RAG_DB_PATH=' . $dbPath);

    $staleInfo = RagManager::getStaleFiles();
    expect($staleInfo['changed'])->toBe([$stale]);
    expect($staleInfo['missing'])->toBe(['tools/__gone__.md']);
    expect($staleInfo['unchanged'])->toBe(count($kb) - 1);
});

test('runIncrementalBuild reindexes changed files and forgets missing ones', function () {
    $kb = incListKbFiles();
    if ($kb === []) {
        test()->skip('rag/knowledge not present in this checkout');
    }
    $source = array_key_first($kb);
    $mtime = $kb[$source];

    $dbPath = incDbPath();
    $rag = new RagSearch($dbPath);
    foreach ($kb as $rel => $mt) {
        incInsertChunk($rag, 'S', 'body', $rel, $mt);
    }
    // 唯一 changed：目标文件 mtime 记旧；唯一 missing：幽灵文件
    $rag->getPdo()->prepare('UPDATE chunk_meta SET source_mtime = ? WHERE rowid IN (SELECT rowid FROM docs WHERE source = ?)')
        ->execute([$mtime - 10, $source]);
    incInsertChunk($rag, 'D', 'ghost body', 'tools/__gone__.md', 1);

    // 备份构建状态文件，测试结束还原（runIncrementalBuild 会写状态文件）
    $statusFile = CORE_PATH . '/runtime/rag_build_status.json';
    $statusBackup = is_file($statusFile) ? (string) file_get_contents($statusFile) : null;

    // 关闭语义通道，嵌入计数应为 0，且不触网
    incSetConfig(['ai.rag.enabled' => false]);

    $GLOBALS['incEnv'] = getenv('RAG_DB_PATH');
    putenv('RAG_DB_PATH=' . $dbPath);

    try {
        $result = RagManager::runIncrementalBuild();

        expect($result['updated'])->toBe(1);
        expect($result['removed'])->toBe(1);
        expect($result['embedded'])->toBe(0);
        expect($result['chunks'])->toBeGreaterThanOrEqual(1);

        $indexed = (new RagSearch($dbPath))->indexedMtimes();
        expect($indexed)->toHaveKey($source, $mtime, '变更文件重建后 mtime 与磁盘一致');
        expect($indexed)->not->toHaveKey('tools/__gone__.md');
        expect(RagManager::getBuildStatus()['status'])->toBe('success');

        // 幂等：再跑一次无变更
        $again = RagManager::runIncrementalBuild();
        expect($again['updated'])->toBe(0);
        expect($again['removed'])->toBe(0);
    } finally {
        if ($statusBackup === null) {
            @unlink($statusFile);
        } else {
            file_put_contents($statusFile, $statusBackup);
        }
        $rp = new ReflectionProperty(RagManager::class, 'memoryStatus');
        $rp->setValue(null, $statusBackup === null ? null : json_decode($statusBackup, true));
    }
});

/* ─── saveDoc / deleteDoc 热更新接线 ─────────────────────── */

test('saveDoc hot-updates the index only when incrementalBuild is enabled', function () {
    $dbPath = incDbPath();
    new RagSearch($dbPath); // 建空索引

    $GLOBALS['incEnv'] = getenv('RAG_DB_PATH');
    putenv('RAG_DB_PATH=' . $dbPath);

    $filename = 'inc_hot_' . bin2hex(random_bytes(4)) . '.md';
    $rel = 'tools/' . $filename;
    $content = "# 热更新测试\n\n## 唯一小节\ninc-hot-marker 内容\n";

    // 开关关闭：保存不触碰索引
    incSetConfig(['ai.rag.incrementalBuild' => false, 'ai.rag.enabled' => false]);
    RagManager::saveDoc('tools', $filename, $content, true);
    $pdo = (new RagSearch($dbPath))->getPdo();
    expect((int) $pdo->query("SELECT count(*) FROM docs WHERE source = '{$rel}'")->fetchColumn())->toBe(0);

    // 开关开启：保存即索引，mtime 记录磁盘真实值
    incSetConfig(['ai.rag.incrementalBuild' => true]);
    RagManager::saveDoc('tools', $filename, $content, false);
    $rows = $pdo->query("SELECT title, body FROM docs WHERE source = '{$rel}'")->fetchAll(PDO::FETCH_ASSOC);
    expect(count($rows))->toBe(1);
    expect($rows[0]['title'])->toBe('热更新测试 > 唯一小节');
    expect($rows[0]['body'])->toContain('inc-hot-marker');
    $fileMtime = (int) filemtime(RagManager::getKnowledgeDir() . '/' . $rel);
    expect((new RagSearch($dbPath))->indexedMtimes()[$rel])->toBe($fileMtime, '热更新记录的 mtime 与磁盘一致，避免下次增量误判');

    // 删除即清理（deleteDoc 对前导斜杠也归一）
    RagManager::deleteDoc('/' . $rel);
    expect((int) (new RagSearch($dbPath))->getPdo()->query("SELECT count(*) FROM docs WHERE source = '{$rel}'")->fetchColumn())->toBe(0);
    expect((int) (new RagSearch($dbPath))->getPdo()->query('SELECT count(*) FROM chunk_meta')->fetchColumn())->toBe(0);
});

test('hotUpdateSource is fail-soft when the index disappears', function () {
    $GLOBALS['incEnv'] = getenv('RAG_DB_PATH');
    putenv('RAG_DB_PATH=' . CORE_PATH . '/tmp/rag_inc_gone_' . uniqid() . '.db');
    incSetConfig(['ai.rag.incrementalBuild' => true, 'ai.rag.enabled' => false]);

    // 索引文件不存在 → 静默跳过，保存/删除照常成功
    $filename = 'inc_noidx_' . bin2hex(random_bytes(4)) . '.md';
    $saved = RagManager::saveDoc('tools', $filename, "# x\n\n内容\n", true);
    expect($saved['path'])->toBe('tools/' . $filename);
    RagManager::deleteDoc($saved['path']);
    expect(is_file(RagManager::getKnowledgeDir() . '/' . $saved['path']))->toBeFalse();
});
