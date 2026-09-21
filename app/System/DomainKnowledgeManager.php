<?php

declare(strict_types=1);

namespace App\System;

use App\Client\RedisClient;

/**
 * LLM 已知领域知识管理服务。
 *
 * 存储并维护由管理员设定的已知领域知识与先验规则条目。
 * 遵循约束：
 * 1. LLM 自身无读写权限，由管理人员在后台统一维护。
 * 2. 并非由 LLM 主动通过 Tools 读取，而是在执行分析前直接拼接入系统提示词（System Prompt）。
 * 3. 提示词段落名称严格命名为“已知领域知识”。
 * 4. 以“条”为管理单位，单条内容严格限制不超过 200 字（UTF-8 字符）。
 */
final class DomainKnowledgeManager
{
    private const FILE_PATH = '/runtime/domain_knowledge.json';
    private const REDIS_KEY = 'ai:domain_knowledge:list';
    public const MAX_ITEM_LENGTH = 200;

    /**
     * 获取所有领域知识条目。
     *
     * @return array<int, array{id: string, content: string, enabled: bool, created_at: int, updated_at: int}>
     */
    public static function getAll(): array
    {
        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                $cached = $redis->get(self::REDIS_KEY);
                if (is_string($cached) && $cached !== '') {
                    $decoded = json_decode($cached, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            } catch (\Throwable) {
                // Redis 降级至本地文件
            }
        }

        $items = self::readFromFile();
        self::syncToRedis($items);
        return $items;
    }

    /**
     * 获取指定 ID 的领域知识条目。
     *
     * @param string $id
     * @return array{id: string, content: string, enabled: bool, created_at: int, updated_at: int}|null
     */
    public static function get(string $id): ?array
    {
        $items = self::getAll();
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
        return null;
    }

    /**
     * 新增一条领域知识。
     *
     * @param string $content 领域知识正文（不超过 200 字）
     * @param bool $enabled 是否默认启用
     * @return array{id: string, content: string, enabled: bool, created_at: int, updated_at: int}
     * @throws \InvalidArgumentException
     */
    public static function add(string $content, bool $enabled = true): array
    {
        $content = trim($content);
        if ($content === '') {
            throw new \InvalidArgumentException('领域知识内容不能为空');
        }

        $charCount = mb_strlen($content, 'UTF-8');
        if ($charCount > self::MAX_ITEM_LENGTH) {
            throw new \InvalidArgumentException("领域知识单条长度不能超过 " . self::MAX_ITEM_LENGTH . " 字，当前为 {$charCount} 字");
        }

        $items = self::readFromFile();
        $now = time();
        $newItem = [
            'id' => 'dk_' . bin2hex(random_bytes(6)),
            'content' => $content,
            'enabled' => $enabled,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // 新增条目置于最前
        array_unshift($items, $newItem);
        self::writeToFile($items);
        self::syncToRedis($items);

        return $newItem;
    }

    /**
     * 更新一条领域知识。
     *
     * @param string $id
     * @param string|null $content 若为 null 则不修改内容
     * @param bool|null $enabled 若为 null 则不修改启用状态
     * @return array{id: string, content: string, enabled: bool, created_at: int, updated_at: int}|null
     * @throws \InvalidArgumentException
     */
    public static function update(string $id, ?string $content = null, ?bool $enabled = null): ?array
    {
        $items = self::readFromFile();
        $targetIndex = null;

        foreach ($items as $index => $item) {
            if ($item['id'] === $id) {
                $targetIndex = $index;
                break;
            }
        }

        if ($targetIndex === null) {
            return null;
        }

        $item = $items[$targetIndex];

        if ($content !== null) {
            $content = trim($content);
            if ($content === '') {
                throw new \InvalidArgumentException('领域知识内容不能为空');
            }
            $charCount = mb_strlen($content, 'UTF-8');
            if ($charCount > self::MAX_ITEM_LENGTH) {
                throw new \InvalidArgumentException("领域知识单条长度不能超过 " . self::MAX_ITEM_LENGTH . " 字，当前为 {$charCount} 字");
            }
            $item['content'] = $content;
        }

        if ($enabled !== null) {
            $item['enabled'] = $enabled;
        }

        $item['updated_at'] = time();
        $items[$targetIndex] = $item;

        self::writeToFile($items);
        self::syncToRedis($items);

        return $item;
    }

    /**
     * 删除指定领域知识条目。
     *
     * @param string $id
     * @return bool
     */
    public static function delete(string $id): bool
    {
        $items = self::readFromFile();
        $initialCount = count($items);

        $items = array_values(array_filter(
            $items,
            static fn(array $item): bool => $item['id'] !== $id
        ));

        if (count($items) === $initialCount) {
            return false;
        }

        self::writeToFile($items);
        self::syncToRedis($items);
        return true;
    }

    /**
     * 格式化所有启用的已知领域知识条目，用于直接拼接入系统提示词（System Prompt）。
     *
     * @return string
     */
    public static function formatForPrompt(): string
    {
        $items = self::getAll();
        $enabledLines = [];

        foreach ($items as $item) {
            if (!empty($item['enabled']) && !empty($item['content'])) {
                $line = trim((string) $item['content']);
                if ($line !== '') {
                    $enabledLines[] = '- ' . $line;
                }
            }
        }

        if ($enabledLines === []) {
            return '';
        }

        return "已知领域知识：\n"
            . "以下是由专业运维团队确立并注入的已知业务规则与领域排障先验知识，你在分析排查时必须严格采纳并作为核心判定依据：\n"
            . implode("\n", $enabledLines);
    }

    /**
     * 从本地磁盘读取。
     *
     * @return array<int, array{id: string, content: string, enabled: bool, created_at: int, updated_at: int}>
     */
    private static function readFromFile(): array
    {
        $path = CORE_PATH . self::FILE_PATH;
        if (!is_file($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * 原子写入本地磁盘。
     *
     * @param array<int, mixed> $items
     */
    private static function writeToFile(array $items): void
    {
        $path = CORE_PATH . self::FILE_PATH;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        $tmpFile = $path . '.tmp.' . uniqid('', true);
        if (@file_put_contents($tmpFile, $json, LOCK_EX) !== false) {
            @rename($tmpFile, $path);
        }
    }

    /**
     * 同步数据到 Redis 缓存。
     *
     * @param array<int, mixed> $items
     */
    private static function syncToRedis(array $items): void
    {
        $redis = RedisClient::getRedis();
        if ($redis === null) {
            return;
        }

        try {
            $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                // 缓存 1 小时，读时自动续期
                $redis->setEx(self::REDIS_KEY, 3600, $json);
            }
        } catch (\Throwable) {
            // 忽略 Redis 同步异常
        }
    }
}
