<?php

declare(strict_types=1);

namespace App\System;

use App\ApiError;
use App\Client\RedisClient;
use App\Config;

/**
 * 边缘安全态势与主动管控联动服务。
 *
 * 负责 IP 黑名单封禁/解封、OpenLiteWaf 快照概览聚合、
 * 以及动态内容违规特征前置拦截校验。
 */
final class SecurityService
{
    private const RULES_FILE = '/runtime/content_reject_rules.json';
    private const BANS_FILE = '/runtime/security_bans.json';
    private const BAN_PREFIX = 'security:ban:';

    /**
     * 查询当前被封禁的 IP 列表。
     *
     * @return array<int, array{ip: string, reason: string, bannedAt: int, expiresAt: int, operator: string, source: string}>
     */
    public static function getBannedIps(): array
    {
        $bans = [];
        $now = time();
        $redis = RedisClient::getRedis();

        // 1. 从 Redis 检索主动封禁记录
        if ($redis !== null) {
            try {
                $keys = $redis->keys(self::BAN_PREFIX . '*');
                if (is_array($keys)) {
                    foreach ($keys as $k) {
                        $val = $redis->get($k);
                        if (is_string($val) && $val !== '') {
                            $decoded = json_decode($val, true);
                            if (is_array($decoded) && isset($decoded['ip'])) {
                                $bans[$decoded['ip']] = [
                                    'ip' => (string) $decoded['ip'],
                                    'reason' => (string) ($decoded['reason'] ?? '管理员主动封禁'),
                                    'bannedAt' => (int) ($decoded['bannedAt'] ?? $now),
                                    'expiresAt' => (int) ($decoded['expiresAt'] ?? ($now + 86400)),
                                    'operator' => (string) ($decoded['operator'] ?? 'admin'),
                                    'source' => 'manual',
                                ];
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                \App\Syslog::error('SecurityService', 'Failed to read redis bans: ' . $e->getMessage());
            }
        }

        // 2. 从本地持久化文件合并（在 Redis 未配置/未启动时兜底）
        $fileBans = self::readFileBans();
        foreach ($fileBans as $ip => $item) {
            if (!isset($bans[$ip]) && (int) ($item['expiresAt'] ?? 0) > $now) {
                $bans[$ip] = $item;
            }
        }

        // 3. 从 OpenLiteWaf 快照合并 WAF 自动拦截名单
        $wafBans = self::readWafSnapshotBans();
        foreach ($wafBans as $item) {
            $ip = $item['ip'];
            if (!isset($bans[$ip])) {
                $bans[$ip] = $item;
            }
        }

        return array_values($bans);
    }

    /**
     * 主动封禁指定 IP。
     *
     * @param string $ip IPv4 或 IPv6 地址
     * @param int $ttlSeconds 封禁时长（秒，0 表示 30 天）
     * @param string $reason 封禁理由
     * @param string $operator 操作人
     * @return array{ip: string, reason: string, expiresAt: int, operator: string}
     */
    public static function banIp(string $ip, int $ttlSeconds = 86400, string $reason = '管理员主动封禁', string $operator = 'admin'): array
    {
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new ApiError(400, "Invalid IP address: {$ip}");
        }

        $ttlSeconds = $ttlSeconds <= 0 ? (30 * 86400) : min(365 * 86400, $ttlSeconds);
        $expiresAt = time() + $ttlSeconds;

        $record = [
            'ip' => $ip,
            'reason' => $reason,
            'bannedAt' => time(),
            'expiresAt' => $expiresAt,
            'operator' => $operator,
            'source' => 'manual',
        ];

        // 1. 写入 Redis
        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                $redis->setEx(self::BAN_PREFIX . $ip, $ttlSeconds, json_encode($record, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                \App\Syslog::error('SecurityService', 'Failed to write redis ban: ' . $e->getMessage());
            }
        }

        // 2. 双写本地持久化文件作为韧性降级
        self::writeFileBan($ip, $record);

        return [
            'ip' => $ip,
            'reason' => $reason,
            'expiresAt' => $expiresAt,
            'operator' => $operator,
        ];
    }

    /**
     * 解封指定 IP。
     *
     * @param string $ip
     * @return bool
     */
    public static function unbanIp(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                $redis->del(self::BAN_PREFIX . $ip);
            } catch (\Throwable $e) {
                \App\Syslog::error('SecurityService', 'Failed to delete redis ban: ' . $e->getMessage());
            }
        }

        self::removeFileBan($ip);

        return true;
    }

    /**
     * 判断指定 IP 是否当前被封禁。
     *
     * @param string $ip
     * @return bool
     */
    public static function isIpBanned(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                if ($redis->exists(self::BAN_PREFIX . $ip)) {
                    return true;
                }
            } catch (\Throwable) {
                // fall through to file check
            }
        }

        $fileBans = self::readFileBans();
        if (isset($fileBans[$ip])) {
            return (int) ($fileBans[$ip]['expiresAt'] ?? 0) > time();
        }

        return false;
    }

    private static function readFileBans(): array
    {
        $path = CORE_PATH . self::BANS_FILE;
        if (!is_file($path)) {
            return [];
        }
        $content = @file_get_contents($path);
        if (!$content) {
            return [];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    private static function writeFileBan(string $ip, array $record): void
    {
        $bans = self::readFileBans();
        $bans[$ip] = $record;
        $path = CORE_PATH . self::BANS_FILE;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($path, json_encode($bans, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private static function removeFileBan(string $ip): void
    {
        $bans = self::readFileBans();
        if (isset($bans[$ip])) {
            unset($bans[$ip]);
            $path = CORE_PATH . self::BANS_FILE;
            @file_put_contents($path, json_encode($bans, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * 获取 WAF 拦截态势概览（来自 OpenLiteWaf 快照或公开接口）。
     *
     * @return array<string, mixed>
     */
    public static function getWafOverview(): array
    {
        $snapshotPath = CORE_PATH . '/OpenLiteWaf/data/snapshot.json';
        if (is_file($snapshotPath)) {
            $content = @file_get_contents($snapshotPath);
            if ($content) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $counters = $data['counters'] ?? [];
                    return [
                        'available' => true,
                        'total_requests' => (int) ($counters['total'] ?? 0),
                        'blocked_total' => (int) ($counters['blocked'] ?? 0),
                        'banned_active' => (int) ($data['banned_active'] ?? 0),
                        'categories' => [
                            'cc' => (int) ($counters['cc'] ?? 0),
                            'sqli' => (int) ($counters['sqli'] ?? 0),
                            'xss' => (int) ($counters['xss'] ?? 0),
                            'traversal' => (int) ($counters['traversal'] ?? 0),
                            'rce' => (int) ($counters['rce'] ?? 0),
                            'probe' => (int) ($counters['probe'] ?? 0),
                        ],
                        'top_ips' => $data['top_ips'] ?? [],
                        'trend' => $data['trend'] ?? [],
                    ];
                }
            }
        }

        return [
            'available' => false,
            'total_requests' => 0,
            'blocked_total' => 0,
            'banned_active' => 0,
            'categories' => [
                'cc' => 0,
                'sqli' => 0,
                'xss' => 0,
                'traversal' => 0,
                'rce' => 0,
                'probe' => 0,
            ],
            'top_ips' => [],
            'trend' => [],
        ];
    }

    /**
     * 获取动态内容拒绝规则。
     *
     * @return array{enabled: bool, keywords: string[], patterns: string[]}
     */
    public static function getContentRules(): array
    {
        $path = CORE_PATH . self::RULES_FILE;
        if (is_file($path)) {
            $content = @file_get_contents($path);
            if ($content) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    return [
                        'enabled' => (bool) ($data['enabled'] ?? true),
                        'keywords' => array_values(array_filter(array_map('trim', (array) ($data['keywords'] ?? [])))),
                        'patterns' => array_values(array_filter(array_map('trim', (array) ($data['patterns'] ?? [])))),
                    ];
                }
            }
        }

        return [
            'enabled' => false,
            'keywords' => [],
            'patterns' => [],
        ];
    }

    /**
     * 保存动态内容拒绝规则。
     *
     * @param array<string, mixed> $payload
     * @return array{enabled: bool, keywords: string[], patterns: string[]}
     */
    public static function saveContentRules(array $payload): array
    {
        $enabled = (bool) ($payload['enabled'] ?? true);
        $keywords = [];
        if (isset($payload['keywords']) && is_array($payload['keywords'])) {
            foreach ($payload['keywords'] as $kw) {
                $kw = trim((string) $kw);
                if ($kw !== '') {
                    $keywords[] = $kw;
                }
            }
        }

        $patterns = [];
        if (isset($payload['patterns']) && is_array($payload['patterns'])) {
            foreach ($payload['patterns'] as $pt) {
                $pt = trim((string) $pt);
                if ($pt !== '') {
                    // 校验正则表达式合法性
                    if (@preg_match($pt, '') === false) {
                        throw new ApiError(400, "Invalid regular expression pattern: {$pt}");
                    }
                    $patterns[] = $pt;
                }
            }
        }

        $data = [
            'enabled' => $enabled,
            'keywords' => array_values(array_unique($keywords)),
            'patterns' => array_values(array_unique($patterns)),
            'updatedAt' => time(),
        ];

        $path = CORE_PATH . self::RULES_FILE;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return [
            'enabled' => $data['enabled'],
            'keywords' => $data['keywords'],
            'patterns' => $data['patterns'],
        ];
    }

    /**
     * 校验日志或文件文本是否包含违规内容。
     *
     * @param string $content
     * @throws ApiError 当命中违规规则时
     */
    public static function validateContent(string $content): void
    {
        if ($content === '') {
            return;
        }

        $rules = self::getContentRules();
        if (!$rules['enabled']) {
            return;
        }

        foreach ($rules['keywords'] as $keyword) {
            if (stripos($content, $keyword) !== false) {
                throw new ApiError(400, "Content rejected: contains prohibited keyword '{$keyword}'");
            }
        }

        foreach ($rules['patterns'] as $pattern) {
            if (@preg_match($pattern, $content) === 1) {
                throw new ApiError(400, "Content rejected: matches prohibited security pattern");
            }
        }
    }

    private static function readWafSnapshotBans(): array
    {
        $snapshotPath = CORE_PATH . '/OpenLiteWaf/data/snapshot.json';
        if (!is_file($snapshotPath)) {
            return [];
        }

        $content = @file_get_contents($snapshotPath);
        if (!$content) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['bans']) || !is_array($data['bans'])) {
            return [];
        }

        $now = time();
        $items = [];
        foreach ($data['bans'] as $b) {
            $ip = (string) ($b['ip'] ?? '');
            $exp = (int) ($b['exp'] ?? 0);
            if ($ip !== '' && $exp > $now) {
                $items[] = [
                    'ip' => $ip,
                    'reason' => 'OpenLiteWaf 自动封禁 (CC 或特征触发)',
                    'bannedAt' => $now - 60,
                    'expiresAt' => $exp,
                    'operator' => 'OpenLiteWaf',
                    'source' => 'waf',
                ];
            }
        }

        return $items;
    }
}
