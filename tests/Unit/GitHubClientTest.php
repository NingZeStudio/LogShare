<?php

declare(strict_types=1);

use App\Client\GitHubClient;
use App\Config;

beforeEach(function () {
    // 重置配置缓存
    Config::ensureFresh();
});

test('resolveRepo maps launcher aliases and full names correctly', function () {
    // 1. 缩写别名解析
    expect(GitHubClient::resolveRepo('fcl'))->toBe('FCL-Team/FoldCraftLauncher');
    expect(GitHubClient::resolveRepo('pojav'))->toBe('PojavLauncherTeam/PojavLauncher');
    expect(GitHubClient::resolveRepo('mg'))->toBe('sparrow-app/MobileGlues');
    expect(GitHubClient::resolveRepo('amc'))->toBe('Amethyst-Launcher/Amethyst');
    expect(GitHubClient::resolveRepo('pgw'))->toBe('Glow-Worm-Project/PojavLauncher-Glow-Worm');
    expect(GitHubClient::resolveRepo('hmcl'))->toBe('HMCL-dev/HMCL');

    // 2. 官方全名解析（不区分大小写）
    expect(GitHubClient::resolveRepo('FoldCraftLauncher'))->toBe('FCL-Team/FoldCraftLauncher');
    expect(GitHubClient::resolveRepo('pojavlauncher'))->toBe('PojavLauncherTeam/PojavLauncher');
    expect(GitHubClient::resolveRepo('MobileGlues'))->toBe('sparrow-app/MobileGlues');
    expect(GitHubClient::resolveRepo('Amethyst-Launcher'))->toBe('Amethyst-Launcher/Amethyst');

    // 3. 规范的第三方 owner/repo 原样保留
    expect(GitHubClient::resolveRepo('custom-owner/custom-repo'))->toBe('custom-owner/custom-repo');
    expect(GitHubClient::resolveRepo('ptitSeb/gl4es'))->toBe('ptitSeb/gl4es');

    // 4. 非法或不存在的输入返回 null
    expect(GitHubClient::resolveRepo(''))->toBeNull();
    expect(GitHubClient::resolveRepo('unknown-alias-xyz'))->toBeNull();
    expect(GitHubClient::resolveRepo('invalid repo name!'))->toBeNull();
});

test('listRepos returns structured markdown with full names and descriptions', function () {
    $markdown = GitHubClient::listRepos();

    expect($markdown)->toContain('FoldCraftLauncher');
    expect($markdown)->toContain('FCL-Team/FoldCraftLauncher');
    expect($markdown)->toContain('PojavLauncher');
    expect($markdown)->toContain('MobileGlues');
    expect($markdown)->toContain('Hello Minecraft! Launcher');
    expect($markdown)->toContain('github_search');
});

test('filterValuableComments removes noise and tags maintainers', function () {
    $raw = [
        ['user' => ['login' => 'spammer1'], 'author_association' => 'NONE', 'body' => '+1'],
        ['user' => ['login' => 'spammer2'], 'author_association' => 'NONE', 'body' => '蹲一个'],
        ['user' => ['login' => 'spammer3'], 'author_association' => 'NONE', 'body' => 'me too!'],
        ['user' => ['login' => 'maintainer_dev'], 'author_association' => 'MEMBER', 'body' => '请在启动器设置中切换渲染器为 MobileGlues 2.1，并关闭光影即可解决。'],
        ['user' => ['login' => 'contributor_user'], 'author_association' => 'CONTRIBUTOR', 'body' => '经过测试，Android 14 确实存在该问题，可通过修改运行时参数规避。'],
    ];

    $filtered = GitHubClient::filterValuableComments($raw);

    expect($filtered)->toHaveCount(2);
    expect($filtered[0]['author'])->toBe('maintainer_dev');
    expect($filtered[0]['role'])->toBe('官方维护者');
    expect($filtered[0]['body'])->toContain('MobileGlues 2.1');

    expect($filtered[1]['author'])->toBe('contributor_user');
    expect($filtered[1]['role'])->toBe('贡献者');
});

test('getTokens extracts and deduplicates token list', function () {
    $tokens = GitHubClient::getTokens();
    expect($tokens)->toBeArray();
});
