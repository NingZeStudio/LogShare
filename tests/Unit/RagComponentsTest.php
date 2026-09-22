<?php

use App\Rag\LexicalIndex;
use App\Rag\SnippetExtractor;
use App\Rag\VectorIndex;

beforeEach(function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE VIRTUAL TABLE docs USING fts5(title, body, source, tokenize = 'porter unicode61')");
    $pdo->exec('CREATE TABLE doc_embeddings(rowid INTEGER PRIMARY KEY, vec BLOB NOT NULL)');
    $pdo->exec("INSERT INTO docs(title, body, source) VALUES
        ('OOM 排查', 'java.lang.OutOfMemoryError 堆内存不足导致崩溃', 'patterns/oom.md'),
        ('启动慢', '服务器启动需要加载全部 mod 与数据包', 'forge/startup.md'),
        ('其他', '无关内容', 'misc/x.md')");
    $this->pdo = $pdo;
});

/* ─── LexicalIndex ───────────────────────────────────────── */

test('LexicalIndex recalls via FTS with bm25 scores', function () {
    $results = (new LexicalIndex($this->pdo))->search('OutOfMemoryError', LexicalIndex::splitTerms('OutOfMemoryError'), 20);

    expect($results)->not->toBe([]);
    expect($results[0]['title'])->toBe('OOM 排查');
    expect(is_numeric($results[0]['score']))->toBeTrue();
});

test('LexicalIndex LIKE path degrades AND to OR and tags score labels', function () {
    $terms = LexicalIndex::splitTerms('启动 数据包');
    $results = (new LexicalIndex($this->pdo))->search('启动 数据包', $terms, 20);

    // 两词同文档 → AND 命中
    expect($results)->not->toBe([]);
    expect($results[0]['score'])->toBe('fallback');

    $terms2 = LexicalIndex::splitTerms('启动 无关内容');
    $or = (new LexicalIndex($this->pdo))->search('启动 无关内容', $terms2, 20);
    expect(array_column($or, 'score'))->toContain('fallback-or');
});

test('LexicalIndex topic filter restricts recall to prefix', function () {
    $results = (new LexicalIndex($this->pdo))->search('OutOfMemoryError', ['OutOfMemoryError'], 20, 'forge');
    expect($results)->toBe([]);

    $results = (new LexicalIndex($this->pdo))->search('OutOfMemoryError', ['OutOfMemoryError'], 20, 'patterns');
    expect($results)->not->toBe([]);
});

/* ─── VectorIndex ────────────────────────────────────────── */

test('VectorIndex ranks by cosine and honors topic prefix join', function () {
    // 3 维玩具向量：doc rowid1 与 query 同向，rowid2 正交
    $stmt = $this->pdo->prepare('INSERT INTO doc_embeddings(rowid, vec) VALUES (?, ?)');
    $stmt->execute([1, VectorIndex::packVector([1.0, 0.0, 0.0])]);
    $stmt->execute([2, VectorIndex::packVector([0.0, 1.0, 0.0])]);
    $stmt->execute([3, VectorIndex::packVector([0.6, 0.8, 0.0])]);

    $hits = (new VectorIndex($this->pdo))->topByCosine([1.0, 0.0, 0.0], 5);
    expect(array_column($hits, 'title'))->toBe(['OOM 排查', '其他', '启动慢']);
    expect($hits[0]['score'])->toBe('vector:1');

    $scoped = (new VectorIndex($this->pdo))->topByCosine([1.0, 0.0, 0.0], 5, 'forge');
    expect(array_column($scoped, 'title'))->toBe(['启动慢']);
});

test('VectorIndex skips dimension-mismatched stored vectors', function () {
    $stmt = $this->pdo->prepare('INSERT INTO doc_embeddings(rowid, vec) VALUES (?, ?)');
    $stmt->execute([1, VectorIndex::packVector([1.0, 0.0])]); // 2 维 ≠ 3 维
    $stmt->execute([2, VectorIndex::packVector([0.0, 1.0, 0.0])]);

    $hits = (new VectorIndex($this->pdo))->topByCosine([0.0, 1.0, 0.0], 5);
    expect(array_column($hits, 'title'))->toBe(['启动慢']);
});

test('pack unpack roundtrip is lossless at float32 precision', function () {
    $vec = [0.125, -3.5, 1.0, 0.0];
    expect(VectorIndex::unpackVector(VectorIndex::packVector($vec)))->toBe($vec);
    expect(VectorIndex::unpackVector(''))->toBe([]);
});

/* ─── SnippetExtractor ───────────────────────────────────── */

test('SnippetExtractor returns full body under threshold and windowed otherwise', function () {
    $short = '短正文。';
    expect(SnippetExtractor::extract($short, ['短']))->toBe($short);

    $body = str_repeat('前置无关内容。', 200) . '目标关键词' . str_repeat('后置无关内容。', 200);
    $snippet = SnippetExtractor::extract($body, ['目标关键词']);
    expect($snippet)->toContain('目标关键词');
    expect($snippet)->toStartWith('…');
    expect(mb_strlen($snippet))->toBeLessThan(mb_strlen($body));
});
