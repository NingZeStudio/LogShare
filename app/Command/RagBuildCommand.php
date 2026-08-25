<?php

declare(strict_types=1);

namespace App\Command;

use App\Rag\RagSearch;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;

#[Command]
class RagBuildCommand extends HyperfCommand
{
    protected ?string $name = 'rag:build';

    public function configure()
    {
        parent::configure();
        $this->setDescription('构建/重建 RAG SQLite FTS5 知识库索引');
    }

    public function handle()
    {
        $knowledgeDir = dirname(__DIR__, 2) . '/rag/knowledge';
        $dbPath = RagSearch::resolveDbPath();
        $semantic = RagSearch::semanticClientFromConfig();

        $this->line('RAG 索引构建');
        $this->line("  知识库目录: {$knowledgeDir}");
        $this->line("  数据库:     {$dbPath}");
        if ($semantic !== null && $semantic->isConfigured()) {
            $this->line('  语义增强:   开启（' . $semantic->describe() . '）');
        } else {
            $this->line('  语义增强:   关闭（纯词法检索）');
        }
        $this->line('---');

        try {
            $rag = new RagSearch($dbPath);
            $result = $rag->buildIndex($knowledgeDir, $semantic);
            $stats = $rag->stats();
        } catch (\Throwable $e) {
            $this->error('构建失败: ' . $e->getMessage());
            return;
        }

        $embeddedNote = $result['embedded'] > 0
            ? ", 已向量化 {$result['embedded']} 条"
            : '';
        $this->info(sprintf(
            '完成: %d 个文件, %d 个分块, 索引共 %d 条%s',
            $result['files'],
            $result['chunks'],
            $stats['chunks'],
            $embeddedNote
        ));
    }
}
