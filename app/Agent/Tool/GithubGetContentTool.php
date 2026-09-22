<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Agent\ToolSession;
use App\Client\GitHubClient;

final class GithubGetContentTool extends AbstractConfiguredTool
{
    public function name(): string
    {
        return 'github_get_content';
    }

    protected function description(): string
    {
        return '获取指定 Issue、PR 或 Discussion 的详细描述、PR 修复说明与维护者采纳的高质量解答。在通过 github_search 定位到高相关条目后调用。';
    }

    protected function parameterSchema(): array
    {
        return $this->objectSchema([
            'repo' => [
                'type' => 'string',
                'description' => '启动器全名、别名或 owner/repo',
            ],
            'number' => [
                'type' => 'integer',
                'description' => 'Issue/PR/Discussion 编号',
            ],
            'type' => [
                'type' => 'string',
                'enum' => ['auto', 'issue', 'pr', 'discussion'],
                'description' => '条目类型：auto（默认自动识别）、issue、pr、discussion',
            ],
        ], ['repo', 'number']);
    }

    public function run(array $arguments, ToolSession $session): string
    {
        if ($session->githubDetailCalls >= 2) {
            return 'GitHub 详情阅读次数已达本次分析上限。请基于已有线索收敛并输出结论。';
        }
        $session->githubDetailCalls++;

        $repo = (string) ($arguments['repo'] ?? '');
        $number = (int) ($arguments['number'] ?? 0);
        $type = (string) ($arguments['type'] ?? 'auto');

        return GitHubClient::getContent($repo, $number, $type);
    }

    public function retryStrategy(): RetryStrategy
    {
        return RetryStrategy::network();
    }
}
