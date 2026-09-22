<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Agent\Support\LogFileTools;
use App\Agent\ToolSession;

final class GrepLogTool extends AbstractConfiguredTool
{
    public function name(): string
    {
        return 'grep_log_file';
    }

    protected function description(): string
    {
        return '在当前日志的指定文件中按关键词逐行检索（类似 grep），返回匹配行号、行内容与前后上下文。'
            . '适合定位特定异常、报错关键字、mod ID 或崩溃特征，避免通读超大文件；获取行号后可按需配合 read_log_file 精确读取。';
    }

    protected function parameterSchema(): array
    {
        return $this->objectSchema([
            'query' => ['type' => 'string', 'description' => '检索关键词或文本短语（如异常类名、模组名、错误关键字）'],
            'filename' => ['type' => 'string', 'description' => '文件名（主文件为 main，或使用 list_log_files 列出的名称；省略则默认 main）'],
            'case_sensitive' => ['type' => 'boolean', 'description' => '是否区分大小写，默认 false（忽略大小写）'],
            'context_lines' => ['type' => 'integer', 'description' => '命中行前后各显示的上下文行数（0-5，默认 1）'],
            'max_matches' => ['type' => 'integer', 'description' => '最大返回匹配项数（1-30，默认 10）'],
        ], ['query']);
    }

    public function run(array $arguments, ToolSession $session): string
    {
        return LogFileTools::grepLogFile($this->logId, $arguments);
    }
}
