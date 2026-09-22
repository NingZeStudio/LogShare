<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Agent\Support\LogFileTools;
use App\Agent\ToolSession;

final class ReadLogTool extends AbstractConfiguredTool
{
    public function name(): string
    {
        return 'read_log_file';
    }

    protected function description(): string
    {
        return '读取当前日志下指定文件的内容。默认返回完整文件；需要控制范围时可使用 line_start/line_end 指定行区间，或使用 offset/max_bytes 指定字节区间。主文件名为 main。';
    }

    protected function parameterSchema(): array
    {
        return $this->objectSchema([
            'filename' => ['type' => 'string', 'description' => '文件名（主文件为 main，或使用 list_log_files 列出的名称）'],
            'line_start' => ['type' => 'integer', 'description' => '起始行号，从 1 开始；省略则从第 1 行开始'],
            'line_end' => ['type' => 'integer', 'description' => '结束行号，包含该行；省略则读取到文件末尾'],
            'offset' => ['type' => 'integer', 'description' => '字节起始位置；使用行区间时不要设置'],
            'max_bytes' => ['type' => 'integer', 'description' => '字节读取模式下的最大字节数；使用行区间时不要设置'],
        ], ['filename']);
    }

    public function run(array $arguments, ToolSession $session): string
    {
        return LogFileTools::readLogFile($this->logId, $arguments, $session);
    }
}
