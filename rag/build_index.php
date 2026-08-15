<?php

/**
 * Build / rebuild the SQLite FTS5 index from the knowledge base.
 *
 * Usage:
 *   php rag/build_index.php [knowledge_dir] [db_path]
 *
 * Defaults:
 *   knowledge_dir = <rag>/knowledge
 *   db_path       = $RAG_DB_PATH or <rag>/index.db
 */

require_once __DIR__ . '/RagSearch.php';

$ragDir = __DIR__;
$knowledgeDir = $argv[1] ?? ($ragDir . '/knowledge');
$dbPath = $argv[2] ?? (getenv('RAG_DB_PATH') ?: $ragDir . '/index.db');

echo "RAG 索引构建\n";
echo "  知识库目录: {$knowledgeDir}\n";
echo "  数据库:     {$dbPath}\n";
echo "---\n";

try {
    $rag = new RagSearch($dbPath);
    $result = $rag->buildIndex($knowledgeDir);
    $stats = $rag->stats();
} catch (Throwable $e) {
    fwrite(STDERR, "构建失败: " . $e->getMessage() . "\n");
    exit(1);
}

printf("完成: %d 个文件, %d 个分块, 索引共 %d 条\n", $result['files'], $result['chunks'], $stats['chunks']);
