<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Agent\Support\LogFileTools;
use App\Agent\ToolSession;

final class ListLogFilesTool extends AbstractConfiguredTool
{
    public function name(): string
    {
        return 'list_log_files';
    }

    protected function description(): string
    {
        return '列出当前日志 ID 下的所有文件（含主文件与附加文件）。';
    }

    protected function parameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function run(array $arguments, ToolSession $session): string
    {
        return LogFileTools::listLogFiles($this->logId);
    }
}
