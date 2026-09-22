<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Agent\ToolSession;
use App\Client\GitHubClient;

final class GithubListReposTool extends AbstractConfiguredTool
{
    public function name(): string
    {
        return 'github_list_repos';
    }

    protected function description(): string
    {
        return '列出官方支持与推荐排障的 Minecraft 启动器与渲染器仓库列表（含官方全名、别名、owner/repo 与适用场景）。'
            . '不确定启动器仓库名或检索方向时优先调用。';
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
        return GitHubClient::listRepos();
    }
}
