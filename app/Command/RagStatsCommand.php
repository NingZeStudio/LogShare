<?php

declare(strict_types=1);

namespace App\Command;

use App\Rag\RagManager;
use App\Rag\RagSearch;
use App\Rag\RetrievalMetrics;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;

#[Command]
class RagStatsCommand extends HyperfCommand
{
    protected ?string $name = 'rag:stats';

    public function configure()
    {
        parent::configure();
        $this->setDescription('查看 RAG 索引状态与检索遥测聚合（命中率、各阶段耗时、慢查询）');
        $this->addOption('date', 'd', \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, '查看指定日期（Y-m-d）的聚合，默认今天');
        $this->addOption('slow', 's', \Symfony\Component\Console\Input\InputOption::VALUE_NONE, '显示最近的慢查询明细');
    }

    public function handle()
    {
        $date = (string) ($this->input->getOption('date') ?? date('Y-m-d'));

        $this->line('── 索引状态 ─────────────────────────────');
        $stats = RagManager::getStats();
        $this->line('  数据库:     ' . $stats['dbPath'] . ($stats['exists'] ? '' : '（不存在）'));
        $this->line('  分块:       ' . $stats['chunks'] . ' 条，已向量化 ' . $stats['embedded'] . ' 条');
        $this->line('  语义通道:   ' . ($stats['semanticEnabled'] ? '开启' : '关闭（纯词法）'));
        $this->line('  构建状态:   ' . (string) ($stats['buildStatus']['status'] ?? 'idle'));

        $strategy = RagSearch::chunkStrategyFromConfig();
        $this->line('  分块策略:   ' . $strategy->value
            . '｜rerank: ' . (RagSearch::rerankEnabled() ? 'on' : 'off')
            . '｜增量: ' . (RagSearch::incrementalBuildEnabled() ? 'on' : 'off'));

        $this->line('── 检索遥测（' . $date . '）─────────────────');
        $summary = RetrievalMetrics::summary($date);
        if ($summary === null) {
            $this->line('  （无数据：Redis 不可用或当日无检索）');
        } else {
            $queries = (int) ($summary['queries'] ?? 0);
            $this->line('  检索量:     ' . $queries);
            if ($queries > 0) {
                $zero = (int) ($summary['zero_results'] ?? 0);
                $this->line(sprintf('  命中率:     %.1f%%（空结果 %d 次）', (1 - $zero / $queries) * 100, $zero));
                $vs = (int) ($summary['vector_searches'] ?? 0);
                if ($vs > 0) {
                    $this->line(sprintf(
                        '  向量召回:   平均 %.1f 条/查询（%d 次语义检索）',
                        (int) ($summary['vector_hits_sum'] ?? 0) / $vs,
                        $vs
                    ));
                }
                foreach (['total_ms', 'rewrite_ms', 'lexical_ms', 'semantic_ms'] as $f) {
                    if (isset($summary[$f . ':sum'])) {
                        $this->line(sprintf(
                            '  %s 平均: %.1f ms（max %s ms）',
                            $f,
                            (float) $summary[$f . ':sum'] / max(1, $queries),
                            (string) ($summary[$f . ':max'] ?? '-')
                        ));
                    }
                }
                $this->line('  改写:       ' . (int) ($summary['rewritten'] ?? 0)
                    . ' 精排: ' . (int) ($summary['reranked'] ?? 0)
                    . ' 结果缓存命中: ' . (int) ($summary['result_cache'] ?? 0)
                    . ' 向量缓存命中: ' . (int) ($summary['embed_cache_hits'] ?? 0)
                    . ' embedding 调用: ' . (int) ($summary['embed_api_calls'] ?? 0));
                $this->line('  语义降级:   ' . (int) ($summary['semantic_errors'] ?? 0)
                    . ' 慢查询: ' . (int) ($summary['slow'] ?? 0));
            }
        }

        if ($this->input->getOption('slow')) {
            $this->line('── 慢查询明细（新→旧）───────────────────');
            $rows = RetrievalMetrics::recentSlow(10);
            if ($rows === []) {
                $this->line('  （无）');
            }
            foreach ($rows as $row) {
                $this->line(sprintf(
                    '  %s  %sms  lex %sms vec %sms hits %s  %s',
                    date('m-d H:i:s', (int) ($row['at'] ?? 0)),
                    (string) round((float) ($row['total_ms'] ?? 0), 1),
                    (string) round((float) ($row['lexical_ms'] ?? 0), 1),
                    (string) round((float) ($row['semantic_ms'] ?? 0), 1),
                    (string) ($row['vector_hits'] ?? '-'),
                    mb_substr((string) ($row['query'] ?? ''), 0, 60)
                ));
            }
        }

        return self::SUCCESS;
    }
}
