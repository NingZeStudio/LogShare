<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Agent\Support\McpClientFactory;
use App\Agent\ToolSession;

final class ListTopicsTool extends AbstractConfiguredTool
{
    public function name(): string
    {
        return 'list_topics';
    }

    protected function description(): string
    {
        return '列出内置知识库的主题地图（目录、说明与内容样本）。不确定检索方向、或 rag_search 连续无结果时调用；'
            . '看完地图后应带着明确目标词去 rag_search（可配合 topic 参数定向），不要看完地图就停止分析。';
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
        $endpoint = $this->config['mcp']['rag'] ?? [];
        return McpClientFactory::call('list_topics', [], $endpoint, $session);
    }
}
