<?php

declare(strict_types=1);

namespace App\Agent;

use App\ApiError;
use App\Config;

/**
 * LogAgent 系统提示词版本化管理服务。
 *
 * 管理内置提示词（v1）与运行期自定义版本（ai.agent.prompts），
 * 支持免发版热更新、A/B 灰度切换、Fork 衍生与激活。
 */
final class PromptManager
{
    public const SYSTEM_DEFAULT_VERSION = 'v1';

    /**
     * 获取所有可用提示词版本列表。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listPrompts(): array
    {
        $config = Config::Get('ai');
        $agentConfig = $config['agent'] ?? [];
        $activeVersion = (string) ($agentConfig['promptVersion'] ?? self::SYSTEM_DEFAULT_VERSION);
        $customPrompts = $agentConfig['prompts'] ?? [];

        $list = [];

        // 1. 系统内置基础版本 (v1)
        $defaultContent = (new PromptBuilder())->defaultSystemPrompt(null);
        $list[] = [
            'version' => self::SYSTEM_DEFAULT_VERSION,
            'name' => '系统内置稳定版 (v1)',
            'isSystem' => true,
            'active' => $activeVersion === self::SYSTEM_DEFAULT_VERSION,
            'length' => mb_strlen($defaultContent),
            'contentSnippet' => mb_substr($defaultContent, 0, 150) . '...',
            'description' => '系统预置标准排障提示词，包含因果追踪硬性约束与末尾结构化 JSON 输出规范。',
        ];

        // 2. 自定义提示词版本
        if (is_array($customPrompts)) {
            foreach ($customPrompts as $ver => $content) {
                $verStr = (string) $ver;
                if ($verStr === self::SYSTEM_DEFAULT_VERSION) {
                    continue;
                }
                $cStr = is_string($content) ? $content : '';
                $list[] = [
                    'version' => $verStr,
                    'name' => "自定义版本 ({$verStr})",
                    'isSystem' => false,
                    'active' => $activeVersion === $verStr,
                    'length' => mb_strlen($cStr),
                    'contentSnippet' => mb_substr($cStr, 0, 150) . '...',
                    'description' => '管理员在线维护的自定义系统提示词。',
                ];
            }
        }

        return $list;
    }

    /**
     * 获取指定版本的提示词完整内容。
     */
    public static function getPrompt(string $version): array
    {
        $config = Config::Get('ai');
        $agentConfig = $config['agent'] ?? [];
        $activeVersion = (string) ($agentConfig['promptVersion'] ?? self::SYSTEM_DEFAULT_VERSION);

        if ($version === self::SYSTEM_DEFAULT_VERSION) {
            $content = (new PromptBuilder())->defaultSystemPrompt(null);
            return [
                'version' => self::SYSTEM_DEFAULT_VERSION,
                'name' => '系统内置稳定版 (v1)',
                'isSystem' => true,
                'active' => $activeVersion === self::SYSTEM_DEFAULT_VERSION,
                'content' => $content,
                'length' => mb_strlen($content),
            ];
        }

        $customPrompts = $agentConfig['prompts'] ?? [];
        if (!is_array($customPrompts) || !isset($customPrompts[$version])) {
            throw new ApiError(404, "Prompt version [{$version}] not found.");
        }

        $content = (string) $customPrompts[$version];
        return [
            'version' => $version,
            'name' => "自定义版本 ({$version})",
            'isSystem' => false,
            'active' => $activeVersion === $version,
            'content' => $content,
            'length' => mb_strlen($content),
        ];
    }

    /**
     * 创建或更新提示词版本。
     */
    public static function savePrompt(string $version, string $content, bool $isNew = false, ?string $forkFrom = null): void
    {
        $version = trim($version);
        if ($version === '') {
            throw new ApiError(400, 'Prompt version key cannot be empty.');
        }

        if ($version === self::SYSTEM_DEFAULT_VERSION) {
            throw new ApiError(400, 'Built-in version [v1] is immutable. Please create a new version like v2 or v1-custom.');
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $version)) {
            throw new ApiError(400, 'Version key must contain only letters, numbers, dashes, and underscores.');
        }

        if (trim($content) === '') {
            // 支持从现有版本 fork
            if ($forkFrom !== null && $forkFrom !== '') {
                $forkTarget = self::getPrompt($forkFrom);
                $content = $forkTarget['content'];
            } else {
                throw new ApiError(400, 'Prompt content cannot be empty.');
            }
        }

        $rawDynamic = Config::getDynamicConfigRaw();
        $agent = $rawDynamic['ai']['agent'] ?? (Config::Get('ai')['agent'] ?? []);
        if (!is_array($agent)) {
            $agent = [];
        }
        $prompts = $agent['prompts'] ?? [];
        if (!is_array($prompts)) {
            $prompts = [];
        }

        if ($isNew && isset($prompts[$version])) {
            throw new ApiError(409, "Prompt version [{$version}] already exists.");
        }

        $prompts[$version] = $content;
        $agent['prompts'] = $prompts;

        $update = [
            'ai' => [
                'agent' => $agent,
            ],
        ];

        Config::saveDynamic($update);
        Config::touchDynamicConfig();
    }

    /**
     * 删除指定的提示词版本。
     */
    public static function deletePrompt(string $version): void
    {
        $version = trim($version);
        if ($version === self::SYSTEM_DEFAULT_VERSION) {
            throw new ApiError(400, 'Cannot delete built-in version [v1].');
        }

        $config = Config::Get('ai');
        $activeVersion = (string) ($config['agent']['promptVersion'] ?? self::SYSTEM_DEFAULT_VERSION);
        if ($version === $activeVersion) {
            throw new ApiError(400, "Cannot delete active prompt version [{$version}]. Please switch to another version first.");
        }

        $rawDynamic = Config::getDynamicConfigRaw();
        $agent = $rawDynamic['ai']['agent'] ?? ($config['agent'] ?? []);
        if (!is_array($agent)) {
            $agent = [];
        }
        $prompts = $agent['prompts'] ?? [];
        if (!is_array($prompts) || !isset($prompts[$version])) {
            throw new ApiError(404, "Prompt version [{$version}] not found.");
        }

        unset($prompts[$version]);
        $agent['prompts'] = $prompts;

        $update = [
            'ai' => [
                'agent' => $agent,
            ],
        ];

        Config::saveDynamic($update);
        Config::touchDynamicConfig();
    }

    /**
     * 激活指定的提示词版本。
     */
    public static function activatePrompt(string $version): void
    {
        $version = trim($version);
        if ($version !== self::SYSTEM_DEFAULT_VERSION) {
            $config = Config::Get('ai');
            $prompts = $config['agent']['prompts'] ?? [];
            if (!is_array($prompts) || !isset($prompts[$version])) {
                throw new ApiError(404, "Cannot activate non-existent version [{$version}].");
            }
        }

        $rawDynamic = Config::getDynamicConfigRaw();
        $agent = $rawDynamic['ai']['agent'] ?? (Config::Get('ai')['agent'] ?? []);
        if (!is_array($agent)) {
            $agent = [];
        }
        $agent['promptVersion'] = $version;

        $update = [
            'ai' => [
                'agent' => $agent,
            ],
        ];

        Config::saveDynamic($update);
        Config::touchDynamicConfig();
    }
}
