<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Agent\ToolSession;
use App\Client\GitHubClient;

final class GithubSearchTool extends AbstractConfiguredTool
{
    public function name(): string
    {
        return 'github_search';
    }

    protected function description(): string
    {
        return '在指定开源启动器或渲染器的 GitHub 仓库中，按关键词联合检索相关的 Issues、Pull Requests（很多崩溃修复记录在 PR 解决说明中）与 Discussions。'
            . '日志中出现启动器名称（如 FoldCraftLauncher、PojavLauncher 等）或渲染器（MobileGlues、gl4es 等）报错时使用。';
    }

    protected function parameterSchema(): array
    {
        return $this->objectSchema([
            'repo' => [
                'type' => 'string',
                'description' => '启动器或渲染器名称：支持官方全名（如 FoldCraftLauncher、PojavLauncher、MobileGlues）、常用别名（如 fcl、pojav、mg、hmcl）或规范的 owner/repo（可先用 github_list_repos 查看）',
            ],
            'query' => [
                'type' => 'string',
                'description' => '检索关键词或报错原文信号（如异常类名、SIGSEGV、崩溃特征短语、中文症状）',
            ],
            'type' => [
                'type' => 'string',
                'enum' => ['all', 'issue', 'pr', 'discussion'],
                'description' => '检索范围：all（默认，综合检索）、issue（仅工单）、pr（仅合并请求/代码修复）、discussion（仅问答讨论）',
            ],
            'state' => [
                'type' => 'string',
                'enum' => ['all', 'closed', 'open'],
                'description' => '状态：all（默认）、closed（已解决/已合并，排障优先）、open（开放中）',
            ],
            'max_results' => [
                'type' => 'integer',
                'description' => '最大返回条目数（1-10，默认 5）',
            ],
        ], ['repo', 'query']);
    }

    public function run(array $arguments, ToolSession $session): string
    {
        if ($session->githubSearchCalls >= 2) {
            return 'GitHub 检索次数已达本次分析上限。请基于已有线索收敛并输出结论，未能核实的信息在结论中明确标注。';
        }
        $session->githubSearchCalls++;

        $repo = (string) ($arguments['repo'] ?? '');
        $query = (string) ($arguments['query'] ?? '');
        $type = (string) ($arguments['type'] ?? 'all');
        $state = (string) ($arguments['state'] ?? 'all');
        $maxResults = (int) ($arguments['max_results'] ?? 5);

        return GitHubClient::search($repo, $query, $type, $state, $maxResults);
    }

    public function retryStrategy(): RetryStrategy
    {
        return RetryStrategy::network();
    }
}
