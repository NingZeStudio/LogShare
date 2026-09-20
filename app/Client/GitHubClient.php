<?php

declare(strict_types=1);

namespace App\Client;

use App\Config;
use App\Syslog;

/**
 * GitHubClient: Swoole 协程安全的 GitHub API 客户端。
 *
 * 职责：
 * 1. 启动器与渲染器官方仓库映射与别名解析（含全名展示）；
 * 2. 多 GitHub Tokens 池化管理：轮询负载均衡、Rate-Limit 状态感知与自动故障切换（Failover）；
 * 3. 联合检索：REST Search API 检索 Issues & PRs，GraphQL API 检索 Discussions；
 * 4. 详情与高赞解答抓取：OP 描述、PR 修复说明、维护者回复与采纳回答（Accepted Answer）提取；
 * 5. 防噪过滤与预算控制：自动剔除无意义水帖评论，单次输出限制在 12KB 以内；
 * 6. Redis 缓存层：避免高频重复请求，降低延迟并节约配额。
 */
class GitHubClient
{
    private const USER_AGENT = 'LogShare-Agent/1.0 (+https://github.com/NingZeStudio/LogShare)';
    private const MAX_CONTENT_BYTES = 12000;
    private const MAX_RETRIES_PER_CALL = 3;

    /**
     * 默认预置的知名 Minecraft 启动器与渲染器官方仓库字典。
     * 可通过 Config::Get('github')['repos'] 扩展或覆盖。
     */
    public const DEFAULT_REPOS = [
        'fcl' => [
            'name' => 'FoldCraftLauncher',
            'repo' => 'FCL-Team/FoldCraftLauncher',
            'aliases' => ['fcl', 'foldcraft', 'foldcraftlauncher'],
            'desc' => 'Android 平台移动端启动器，涵盖 Java 运行时安装、触控布局、模组配置等问题',
        ],
        'pojav' => [
            'name' => 'PojavLauncher',
            'repo' => 'PojavLauncherTeam/PojavLauncher',
            'aliases' => ['pojav', 'pojavlauncher'],
            'desc' => '全球主流移动端启动器，包含 libglfw、OpenAL、Java 堆栈与安卓各版本兼容问题',
        ],
        'amethyst' => [
            'name' => 'Amethyst-Launcher',
            'repo' => 'Amethyst-Launcher/Amethyst',
            'aliases' => ['amc', 'amethyst', 'amethyst-launcher'],
            'desc' => 'PojavLauncher 官方续作，针对新 Android 版本权限、运行时与环境适配',
        ],
        'pgw' => [
            'name' => 'PojavLauncher-Glow-Worm',
            'repo' => 'Glow-Worm-Project/PojavLauncher-Glow-Worm',
            'aliases' => ['pgw', 'glowworm', 'pojav-glow-worm'],
            'desc' => '移动端 Pojav 增强分支，主打扩展渲染器支持与运行优化',
        ],
        'mobileglues' => [
            'name' => 'MobileGlues',
            'repo' => 'sparrow-app/MobileGlues',
            'aliases' => ['mg', 'mobileglues'],
            'desc' => '移动端渲染桥接组件，专门处理图形崩溃、着色器报错与光影兼容性问题',
        ],
        'hmcl' => [
            'name' => 'Hello Minecraft! Launcher',
            'repo' => 'HMCL-dev/HMCL',
            'aliases' => ['hmcl', 'hellominecraftlauncher'],
            'desc' => '主流跨平台桌面启动器，涵盖 Fabric/Forge 安装器故障、Java 下载与账号授权',
        ],
    ];

    /**
     * 进程级 Token 轮询指针与健康状态跟踪。
     * 格式：[ 'token_hash' => ['cooldown_until' => timestamp, 'remaining' => int, 'reset_time' => int] ]
     *
     * @var array<string, array{cooldown_until: int, remaining: int, reset_time: int}>
     */
    private static array $tokenHealth = [];
    private static int $tokenIndex = 0;

    /**
     * 列出当前支持查询的 Minecraft 启动器与渲染器仓库列表（供 Agent 获取全局认知）。
     *
     * @return string Markdown 格式的仓库全名、别名与使用场景清单
     */
    public static function listRepos(): string
    {
        $repos = self::getAllRepos();
        $lines = ["# 官方推荐 Minecraft 启动器与渲染器排障仓库列表：\n"];

        foreach ($repos as $item) {
            $name = $item['name'];
            $repo = $item['repo'];
            $desc = $item['desc'] ?? '';
            $aliases = is_array($item['aliases'] ?? null) ? implode(', ', $item['aliases']) : '';

            $lines[] = sprintf(
                "- **%s**（仓库: `%s`%s）\n  适用场景: %s",
                $name,
                $repo,
                $aliases !== '' ? "，别名: {$aliases}" : '',
                $desc
            );
        }

        $lines[] = "\n提示：调用 `github_search` 或 `github_get_content` 时，`repo` 参数既可填写启动器全名（如 `FoldCraftLauncher`），也可使用常用别名（如 `fcl`）或完整仓库名（如 `FCL-Team/FoldCraftLauncher`）。";

        return implode("\n", $lines);
    }

    /**
     * 在指定仓库中检索 Issues、PRs 与 Discussions。
     *
     * @param string $repoInput 启动器全名、别名或 owner/repo
     * @param string $query 检索关键词（如异常类名、崩溃特征）
     * @param string $type all | issue | pr | discussion
     * @param string $state all | closed | open
     * @param int $maxResults 返回数量上限（1-10，默认 5）
     * @return string Markdown 格式的检索结果
     */
    public static function search(
        string $repoInput,
        string $query,
        string $type = 'all',
        string $state = 'all',
        int $maxResults = 5
    ): string {
        $query = trim($query);
        if ($query === '') {
            return '检索词不能为空。请提供具体的报错类名、异常特征或关键词。';
        }

        $resolvedRepo = self::resolveRepo($repoInput);
        if ($resolvedRepo === null) {
            return sprintf('未找到对应的仓库: "%s"。请使用 `github_list_repos` 查看受支持的启动器全名与别名，或直接提供合法的 "owner/repo" 路径。', $repoInput);
        }

        $type = strtolower($type);
        if (!in_array($type, ['all', 'issue', 'pr', 'discussion'], true)) {
            $type = 'all';
        }
        $state = strtolower($state);
        if (!in_array($state, ['all', 'closed', 'open'], true)) {
            $state = 'all';
        }
        $maxResults = max(1, min(10, $maxResults));

        // 尝试命中 Redis 缓存
        $cacheKey = sprintf('github:search:%s:%s:%s:%d:%s', md5($resolvedRepo), $type, $state, $maxResults, md5($query));
        $cached = self::getCached($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $entries = [];

        // 1. REST Search: 检索 Issues / PRs
        if ($type === 'all' || $type === 'issue' || $type === 'pr') {
            $issueEntries = self::searchRestIssues($resolvedRepo, $query, $type, $state, $maxResults);
            $entries = array_merge($entries, $issueEntries);
        }

        // 2. GraphQL Search: 检索 Discussions
        $hasTokens = !empty(self::getTokens());
        if ($type === 'all' || $type === 'discussion') {
            if ($hasTokens) {
                $discussionEntries = self::searchGraphqlDiscussions($resolvedRepo, $query, $maxResults);
                $entries = array_merge($entries, $discussionEntries);
            } elseif ($type === 'discussion') {
                return "检索 Discussions 需要配置 GitHub Personal Access Token。当前系统未配置 Token，无法检索 Discussions，建议配置 GITHUB_TOKENS 或将 type 参数设为 'issue' / 'pr'。";
            }
        }

        if (empty($entries)) {
            $msg = sprintf("在仓库 `%s` 中检索 \"%s\" 未找到相关 Issue、PR 或 Discussion。\n建议方向：\n1. 缩短关键词或剔除无关包名前缀；\n2. 尝试英文异常类名与中文症状互译；\n3. 查看 `github_list_repos` 确认是否需在其他关联仓库（如 MobileGlues 渲染器）排查。", $resolvedRepo, $query);
            self::setCached($cacheKey, $msg, 600);
            return $msg;
        }

        // 排序与截断：按更新时间或重要度截取前 $maxResults 条
        usort($entries, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
        $entries = array_slice($entries, 0, $maxResults);

        $out = [
            sprintf("在 `%s` 中按关键词 \"%s\" 检索结果（共 %d 条）：\n", $resolvedRepo, $query, count($entries)),
        ];

        foreach ($entries as $i => $item) {
            $index = $i + 1;
            $itemType = $item['kind'] ?? 'Issue'; // Issue / PR / Discussion
            $number = $item['number'] ?? 0;
            $title = $item['title'] ?? '';
            $status = $item['status'] ?? '';
            $labels = !empty($item['labels']) ? ' | 标签: ' . implode(', ', $item['labels']) : '';
            $updated = !empty($item['updated_at']) ? substr($item['updated_at'], 0, 10) : '';
            $isAnswered = !empty($item['has_answer']) ? ' **[已采纳解决方案]**' : '';
            $snippet = !empty($item['snippet']) ? "\n   > " . str_replace("\n", "\n   > ", $item['snippet']) : '';

            $out[] = sprintf(
                "%d. [%s #%d (%s)] %s%s\n   更新: %s%s%s",
                $index,
                $itemType,
                $number,
                $status,
                $title,
                $isAnswered,
                $updated,
                $labels,
                $snippet
            );
        }

        $out[] = "\n后续建议：针对最相关的条目，调用 `github_get_content(repo: \"{$resolvedRepo}\", number: <编号>)` 读取详细解决方案与维护者解答。";
        $resultText = implode("\n", $out);

        self::setCached($cacheKey, $resultText, self::getCacheTtl());
        return $resultText;
    }

    /**
     * 读取指定 Issue、PR 或 Discussion 的正文、PR 解决说明与精选高质量回复。
     *
     * @param string $repoInput 启动器全名、别名或 owner/repo
     * @param int $number Issue/PR/Discussion 编号
     * @param string $type auto | issue | pr | discussion
     * @return string Markdown 格式的精炼排障正文
     */
    public static function getContent(string $repoInput, int $number, string $type = 'auto'): string
    {
        if ($number <= 0) {
            return '无效的编号参数。';
        }

        $resolvedRepo = self::resolveRepo($repoInput);
        if ($resolvedRepo === null) {
            return sprintf('未找到对应的仓库: "%s"。请检查仓库名称或使用 `github_list_repos`。', $repoInput);
        }

        $type = strtolower($type);
        $cacheKey = sprintf('github:content:%s:%s:%d', md5($resolvedRepo), $type, $number);
        $cached = self::getCached($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // 1. 若指定为 discussion 或 auto 且配置了 Token，尝试从 GraphQL 读取 Discussion
        if ($type === 'discussion' || ($type === 'auto' && !empty(self::getTokens()))) {
            $discussionData = self::getGraphqlDiscussion($resolvedRepo, $number);
            if ($discussionData !== null) {
                $rendered = self::renderDiscussionDetail($resolvedRepo, $discussionData);
                self::setCached($cacheKey, $rendered, self::getContentCacheTtl($discussionData['closed'] ?? false));
                return $rendered;
            }
            if ($type === 'discussion') {
                return sprintf('在仓库 `%s` 中未找到编号为 #%d 的 Discussion。', $resolvedRepo, $number);
            }
        }

        // 2. 从 REST API 获取 Issue 或 PR 详情
        $issueData = self::getRestIssue($resolvedRepo, $number);
        if ($issueData === null) {
            return sprintf('在仓库 `%s` 中未能获取编号为 #%d 的 Issue/PR。可能该条目不存在或当前访问受限。', $resolvedRepo, $number);
        }

        $commentsData = self::getRestIssueComments($resolvedRepo, $number);
        $rendered = self::renderIssueDetail($resolvedRepo, $issueData, $commentsData);

        $isClosed = ($issueData['state'] ?? '') === 'closed';
        self::setCached($cacheKey, $rendered, self::getContentCacheTtl($isClosed));
        return $rendered;
    }

    /**
     * 解析用户传入的仓库参数（全称、别名或 owner/repo）。
     *
     * @param string $input
     * @return string|null 规范的 owner/repo，未匹配且不合规则返回 null
     */
    public static function resolveRepo(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $repos = self::getAllRepos();
        $inputLower = strtolower($input);

        // 1. 别名与全名比对（不区分大小写）
        foreach ($repos as $item) {
            if (strtolower($item['name']) === $inputLower) {
                return $item['repo'];
            }
            if (strtolower($item['repo']) === $inputLower) {
                return $item['repo'];
            }
            $aliases = is_array($item['aliases'] ?? null) ? array_map('strtolower', $item['aliases']) : [];
            if (in_array($inputLower, $aliases, true)) {
                return $item['repo'];
            }
        }

        // 2. 直接符合 owner/repo 正则
        if (preg_match('/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+$/', $input)) {
            return $input;
        }

        return null;
    }

    /**
     * 获取全部已配置与默认的仓库映射字典。
     *
     * @return array<string, array{name: string, repo: string, aliases?: array<string>, desc?: string}>
     */
    public static function getAllRepos(): array
    {
        $config = Config::Get('github');
        $configuredRepos = $config['repos'] ?? [];
        if (!is_array($configuredRepos)) {
            $configuredRepos = [];
        }

        return array_merge(self::DEFAULT_REPOS, $configuredRepos);
    }

    /* ─────────────────────────────────────────────────────────────
     * REST & GraphQL 查询内部实现
     * ───────────────────────────────────────────────────────────── */

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function searchRestIssues(string $repo, string $query, string $type, string $state, int $limit): array
    {
        $qualifiers = ["repo:{$repo}"];
        if ($type === 'pr') {
            $qualifiers[] = 'is:pr';
        } elseif ($type === 'issue') {
            $qualifiers[] = 'is:issue';
        }
        if ($state === 'closed') {
            $qualifiers[] = 'is:closed';
        } elseif ($state === 'open') {
            $qualifiers[] = 'is:open';
        }

        $fullQuery = implode(' ', $qualifiers) . ' ' . $query;
        $url = 'https://api.github.com/search/issues?q=' . urlencode($fullQuery) . '&per_page=' . $limit;

        $res = self::request('GET', $url);
        if (!is_array($res) || !isset($res['items']) || !is_array($res['items'])) {
            return [];
        }

        $entries = [];
        foreach ($res['items'] as $item) {
            $isPr = isset($item['pull_request']);
            $labels = [];
            if (is_array($item['labels'] ?? null)) {
                foreach ($item['labels'] as $lbl) {
                    if (is_array($lbl) && isset($lbl['name'])) {
                        $labels[] = (string) $lbl['name'];
                    }
                }
            }

            $rawBody = (string) ($item['body'] ?? '');
            $snippet = self::extractSnippet($rawBody, $query);

            $entries[] = [
                'kind' => $isPr ? 'PR' : 'Issue',
                'number' => (int) ($item['number'] ?? 0),
                'title' => (string) ($item['title'] ?? ''),
                'status' => (string) ($item['state'] ?? 'open'),
                'updated_at' => (string) ($item['updated_at'] ?? ''),
                'labels' => $labels,
                'has_answer' => false,
                'snippet' => $snippet,
            ];
        }

        return $entries;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function searchGraphqlDiscussions(string $repo, string $query, int $limit): array
    {
        $tokens = self::getTokens();
        if (empty($tokens)) {
            return [];
        }

        $graphqlQuery = <<<'GRAPHQL'
query($searchQuery: String!, $first: Int!) {
  search(query: $searchQuery, type: DISCUSSION, first: $first) {
    nodes {
      ... on Discussion {
        number
        title
        url
        createdAt
        updatedAt
        isAnswered
        answer {
          body
          author { login }
        }
        body
      }
    }
  }
}
GRAPHQL;

        $variables = [
            'searchQuery' => "repo:{$repo} {$query}",
            'first' => $limit,
        ];

        $res = self::request('POST', 'https://api.github.com/graphql', [
            'query' => $graphqlQuery,
            'variables' => $variables,
        ]);

        if (!is_array($res) || !isset($res['data']['search']['nodes']) || !is_array($res['data']['search']['nodes'])) {
            return [];
        }

        $entries = [];
        foreach ($res['data']['search']['nodes'] as $node) {
            if (!is_array($node) || empty($node['number'])) {
                continue;
            }

            $isAnswered = (bool) ($node['isAnswered'] ?? !empty($node['answer']));
            $snippetSource = '';
            if (!empty($node['answer']['body'])) {
                $snippetSource = '[采纳解答] ' . (string) $node['answer']['body'];
            } else {
                $snippetSource = (string) ($node['body'] ?? '');
            }

            $entries[] = [
                'kind' => 'Discussion',
                'number' => (int) $node['number'],
                'title' => (string) ($node['title'] ?? ''),
                'status' => $isAnswered ? '已解决' : '开放讨论',
                'updated_at' => (string) ($node['updatedAt'] ?? ''),
                'labels' => [],
                'has_answer' => $isAnswered,
                'snippet' => self::extractSnippet($snippetSource, $query),
            ];
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getRestIssue(string $repo, int $number): ?array
    {
        $url = "https://api.github.com/repos/{$repo}/issues/{$number}";
        $res = self::request('GET', $url);
        return is_array($res) && isset($res['number']) ? $res : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function getRestIssueComments(string $repo, int $number): array
    {
        $url = "https://api.github.com/repos/{$repo}/issues/{$number}/comments?per_page=20";
        $res = self::request('GET', $url);
        if (!is_array($res) || isset($res['message'])) {
            return [];
        }
        /** @var array<int, array<string, mixed>> $comments */
        $comments = array_values(array_filter($res, 'is_array'));
        return $comments;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getGraphqlDiscussion(string $repo, int $number): ?array
    {
        $parts = explode('/', $repo, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$owner, $name] = $parts;

        $graphql = <<<'GRAPHQL'
query($owner: String!, $name: String!, $number: Int!) {
  repository(owner: $owner, name: $name) {
    discussion(number: $number) {
      number
      title
      url
      createdAt
      updatedAt
      body
      isAnswered
      answer {
        body
        author { login }
        createdAt
      }
      comments(first: 15) {
        nodes {
          body
          author { login }
          authorAssociation
          createdAt
        }
      }
    }
  }
}
GRAPHQL;

        $res = self::request('POST', 'https://api.github.com/graphql', [
            'query' => $graphql,
            'variables' => [
                'owner' => $owner,
                'name' => $name,
                'number' => $number,
            ],
        ]);

        if (isset($res['data']['repository']['discussion']) && is_array($res['data']['repository']['discussion'])) {
            return $res['data']['repository']['discussion'];
        }

        return null;
    }

    /* ─────────────────────────────────────────────────────────────
     * 结果精炼与 Markdown 渲染
     * ───────────────────────────────────────────────────────────── */

    /**
     * @param array<string, mixed> $issue
     * @param array<int, array<string, mixed>> $comments
     */
    private static function renderIssueDetail(string $repo, array $issue, array $comments): string
    {
        $isPr = isset($issue['pull_request']);
        $typeLabel = $isPr ? 'Pull Request' : 'Issue';
        $number = (int) ($issue['number'] ?? 0);
        $title = (string) ($issue['title'] ?? '');
        $state = (string) ($issue['state'] ?? 'open');
        $author = (string) ($issue['user']['login'] ?? 'unknown');
        $created = substr((string) ($issue['created_at'] ?? ''), 0, 10);
        $body = trim((string) ($issue['body'] ?? ''));

        $lines = [
            sprintf("### [%s #%d] %s", $typeLabel, $number, $title),
            sprintf("- 仓库: `%s` | 状态: **%s** | 作者: `%s` | 创建日期: %s", $repo, $state, $author, $created),
        ];

        if ($isPr) {
            $lines[] = "\n**PR 解决背景与变更说明：**";
        } else {
            $lines[] = "\n**问题描述（OP 楼主正文）：**";
        }

        $lines[] = $body !== '' ? $body : '*(作者未填写正文)*';

        // 提取与过滤评论
        $valuableComments = self::filterValuableComments($comments);
        if (!empty($valuableComments)) {
            $lines[] = "\n---\n**精选回复与排障证据：**";
            foreach ($valuableComments as $c) {
                $cAuthor = $c['author'];
                $role = $c['role'] !== '' ? " ({$c['role']})" : '';
                $lines[] = sprintf("\n**`%s`%s 回复：**\n%s", $cAuthor, $role, $c['body']);
            }
        }

        return self::truncateContent(implode("\n", $lines), self::MAX_CONTENT_BYTES);
    }

    /**
     * @param array<string, mixed> $discussion
     */
    private static function renderDiscussionDetail(string $repo, array $discussion): string
    {
        $number = (int) ($discussion['number'] ?? 0);
        $title = (string) ($discussion['title'] ?? '');
        $created = substr((string) ($discussion['createdAt'] ?? ''), 0, 10);
        $body = trim((string) ($discussion['body'] ?? ''));

        $lines = [
            sprintf("### [Discussion #%d] %s", $number, $title),
            sprintf("- 仓库: `%s` | 状态: **%s** | 创建日期: %s", $repo, !empty($discussion['isAnswered']) ? '已采纳解答' : '讨论中', $created),
            "\n**讨论发起正文：**\n" . ($body !== '' ? $body : '*(无正文)*'),
        ];

        if (!empty($discussion['answer']) && is_array($discussion['answer'])) {
            $ansAuthor = (string) ($discussion['answer']['author']['login'] ?? '维护者');
            $ansBody = trim((string) ($discussion['answer']['body'] ?? ''));
            $lines[] = "\n---\n**★ 官方/楼主采纳的解决方案（Accepted Answer）：**";
            $lines[] = sprintf("**`%s` 回答：**\n%s", $ansAuthor, $ansBody);
        }

        // 其它评论
        $rawComments = [];
        if (isset($discussion['comments']['nodes']) && is_array($discussion['comments']['nodes'])) {
            foreach ($discussion['comments']['nodes'] as $cn) {
                if (is_array($cn)) {
                    $rawComments[] = [
                        'user' => ['login' => $cn['author']['login'] ?? '社区用户'],
                        'author_association' => $cn['authorAssociation'] ?? 'NONE',
                        'body' => $cn['body'] ?? '',
                    ];
                }
            }
        }

        $valuable = self::filterValuableComments($rawComments);
        if (!empty($valuable)) {
            $lines[] = "\n---\n**其他关键讨论跟帖：**";
            foreach ($valuable as $c) {
                $lines[] = sprintf("\n**`%s`%s：**\n%s", $c['author'], $c['role'] !== '' ? " ({$c['role']})" : '', $c['body']);
            }
        }

        return self::truncateContent(implode("\n", $lines), self::MAX_CONTENT_BYTES);
    }

    /**
     * 过滤社区评论中的无意义水帖，提取维护者、解答或有价值长篇回帖。
     *
     * @param array<int, array<string, mixed>> $rawComments
     * @return array<int, array{author: string, role: string, body: string}>
     */
    public static function filterValuableComments(array $rawComments): array
    {
        $filtered = [];
        $noiseRegex = '/^(?:\+1|蹲|顶|蹲一个|同问|me too|same issue|any updates\??|thanks|谢谢|Mark|插眼)[!！\s]*$/iu';

        foreach ($rawComments as $comment) {
            $body = trim((string) ($comment['body'] ?? ''));
            if ($body === '' || preg_match($noiseRegex, $body)) {
                continue;
            }

            $author = (string) ($comment['user']['login'] ?? '社区用户');
            $association = strtoupper((string) ($comment['author_association'] ?? 'NONE'));

            $role = '';
            if (in_array($association, ['OWNER', 'MEMBER', 'COLLABORATOR'], true)) {
                $role = '官方维护者';
            } elseif ($association === 'CONTRIBUTOR') {
                $role = '贡献者';
            }

            $filtered[] = [
                'author' => $author,
                'role' => $role,
                'body' => $body,
            ];

            if (count($filtered) >= 6) {
                break;
            }
        }

        return $filtered;
    }

    /**
     * 提取匹配词附近的精炼摘要。
     */
    private static function extractSnippet(string $text, string $keyword): string
    {
        $clean = preg_replace('/\s+/', ' ', strip_tags($text)) ?? '';
        if ($clean === '') {
            return '';
        }

        $pos = mb_stripos($clean, $keyword);
        if ($pos === false) {
            return mb_substr($clean, 0, 160) . (mb_strlen($clean) > 160 ? '...' : '');
        }

        $start = max(0, $pos - 60);
        $snippet = mb_substr($clean, $start, 180);
        return ($start > 0 ? '...' : '') . $snippet . (mb_strlen($clean) > $start + 180 ? '...' : '');
    }

    /**
     * 截断超长内容并保留整行边界。
     */
    private static function truncateContent(string $content, int $maxBytes): string
    {
        if (strlen($content) <= $maxBytes) {
            return $content;
        }

        $truncated = substr($content, 0, $maxBytes - 120);
        $lastNewline = strrpos($truncated, "\n");
        if ($lastNewline !== false && $lastNewline > $maxBytes * 0.7) {
            $truncated = substr($truncated, 0, $lastNewline);
        }

        return $truncated . "\n\n[... 内容过长，已截取前置排障核心信息。如需更多详情请结合前文线索分析 ...]";
    }

    /* ─────────────────────────────────────────────────────────────
     * 多 Token 轮询、健康状态跟踪与底层网络通信
     * ───────────────────────────────────────────────────────────── */

    /**
     * 发起 GitHub HTTP 请求，支持多 Token 轮询、403/429 故障转移与 cURL 协程安全。
     *
     * @param string $method GET | POST
     * @param string $url 完整 URL
     * @param array<string, mixed>|null $data 请求 Payload
     * @return array<string, mixed>|null
     */
    private static function request(string $method, string $url, ?array $data = null): ?array
    {
        $tokens = self::getTokens();
        $retryLimit = max(1, min(count($tokens), self::MAX_RETRIES_PER_CALL));
        $attempt = 0;

        $config = Config::Get('github');
        $proxy = (string) ($config['proxy'] ?? '');
        $timeout = (int) ($config['timeout'] ?? 8);
        if ($timeout <= 0) {
            $timeout = 8;
        }

        while ($attempt < $retryLimit) {
            $attempt++;
            $activeToken = self::pickToken($tokens);

            $headers = [
                'User-Agent: ' . self::USER_AGENT,
                'Accept: application/vnd.github.v3+json',
            ];
            if ($activeToken !== null && $activeToken !== '') {
                $headers[] = 'Authorization: Bearer ' . $activeToken;
            }

            $ch = curl_init();
            try {
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_FORBID_REUSE => true,
                    CURLOPT_HEADER => true,
                ]);

                if ($proxy !== '') {
                    curl_setopt($ch, CURLOPT_PROXY, $proxy);
                }

                if ($method === 'POST') {
                    curl_setopt($ch, CURLOPT_POST, true);
                    if ($data !== null) {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
                        $headers[] = 'Content-Type: application/json';
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    }
                }

                $response = curl_exec($ch);
                if ($response === false) {
                    $error = curl_error($ch);
                    Syslog::error('GitHubClient', "请求失败 ({$url}): {$error}");
                    return null;
                }

                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $rawHeader = substr((string) $response, 0, $headerSize);
                $rawBody = substr((string) $response, $headerSize);

                // 解析 GitHub 限流标头
                self::updateRateLimits($activeToken, $rawHeader);

                // 若遇到 403/429 且由于速率超限触发，将该 Token 标记冷却并尝试下一个
                if (($statusCode === 403 || $statusCode === 429) && $activeToken !== null) {
                    $decodedBody = json_decode($rawBody, true);
                    $msg = is_array($decodedBody) ? strtolower((string) ($decodedBody['message'] ?? '')) : '';
                    if (str_contains($msg, 'rate limit') || str_contains($msg, 'secondary rate')) {
                        Syslog::error('GitHubClient', "Token 触发限流，进入冷却切换下一个: {$msg}");
                        self::markTokenCooldown($activeToken, 60);
                        continue;
                    }
                }

                if ($statusCode >= 400) {
                    Syslog::error('GitHubClient', "HTTP 状态异常 {$statusCode} ({$url}): " . substr($rawBody, 0, 200));
                    return null;
                }

                $decoded = json_decode($rawBody, true);
                return is_array($decoded) ? $decoded : null;
            } finally {
                if ($ch instanceof \CurlHandle) {
                    curl_close($ch);
                }
                $ch = null;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function getTokens(): array
    {
        $config = Config::Get('github');
        $tokens = $config['tokens'] ?? [];
        if (!is_array($tokens)) {
            $tokens = [];
        }

        // 兼容单 token 配置
        if (isset($config['token']) && is_string($config['token']) && $config['token'] !== '') {
            if (!in_array($config['token'], $tokens, true)) {
                $tokens[] = $config['token'];
            }
        }

        return array_values(array_filter(array_map('trim', $tokens), fn($t) => $t !== ''));
    }

    /**
     * 从 Token 列表中轮询选取一个健康的 Token。
     *
     * @param array<int, string> $tokens
     */
    private static function pickToken(array $tokens): ?string
    {
        if (empty($tokens)) {
            return null;
        }

        $count = count($tokens);
        $now = time();

        for ($i = 0; $i < $count; $i++) {
            $idx = (self::$tokenIndex + $i) % $count;
            $token = $tokens[$idx];
            $key = md5($token);

            $health = self::$tokenHealth[$key] ?? ['cooldown_until' => 0];
            if ($health['cooldown_until'] <= $now) {
                self::$tokenIndex = ($idx + 1) % $count;
                return $token;
            }
        }

        // 所有 Token 均在冷却期，回退第一个
        self::$tokenIndex = (self::$tokenIndex + 1) % $count;
        return $tokens[0] ?? null;
    }

    private static function markTokenCooldown(string $token, int $seconds): void
    {
        $key = md5($token);
        self::$tokenHealth[$key]['cooldown_until'] = time() + $seconds;
    }

    private static function updateRateLimits(?string $token, string $rawHeader): void
    {
        if ($token === null) {
            return;
        }

        $key = md5($token);
        $remaining = null;
        $resetTime = null;

        $lines = explode("\r\n", $rawHeader);
        foreach ($lines as $line) {
            if (stripos($line, 'x-ratelimit-remaining:') === 0) {
                $remaining = (int) trim(substr($line, 22));
            } elseif (stripos($line, 'x-ratelimit-reset:') === 0) {
                $resetTime = (int) trim(substr($line, 18));
            }
        }

        if ($remaining !== null) {
            self::$tokenHealth[$key]['remaining'] = $remaining;
            if ($remaining <= 0 && $resetTime !== null && $resetTime > time()) {
                self::$tokenHealth[$key]['cooldown_until'] = $resetTime;
            }
        }
        if ($resetTime !== null) {
            self::$tokenHealth[$key]['reset_time'] = $resetTime;
        }
    }

    /* ─────────────────────────────────────────────────────────────
     * 缓存与 TTL
     * ───────────────────────────────────────────────────────────── */

    private static function getCached(string $key): ?string
    {
        try {
            $redis = RedisClient::getRedis();
            if ($redis === null) {
                return null;
            }
            $val = $redis->get($key);
            return is_string($val) && $val !== '' ? $val : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function setCached(string $key, string $value, int $ttl): void
    {
        if ($ttl <= 0) {
            return;
        }
        try {
            $redis = RedisClient::getRedis();
            if ($redis !== null) {
                $redis->setex($key, $ttl, $value);
            }
        } catch (\Throwable) {
            // Redis 降级无阻
        }
    }

    private static function getCacheTtl(): int
    {
        $config = Config::Get('github');
        $ttl = (int) ($config['cache_ttl'] ?? 3600);
        return $ttl > 0 ? $ttl : 3600;
    }

    private static function getContentCacheTtl(bool $isClosed): int
    {
        // 已解决/已关闭工单变更极少，缓存 24 小时；未关闭工单缓存 1 小时
        return $isClosed ? 86400 : 3600;
    }
}
