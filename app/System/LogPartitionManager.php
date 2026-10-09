<?php

declare(strict_types=1);

namespace App\System;

use Hyperf\DbConnection\Db;

/**
 * 日志表分区管理。
 *
 * 背景：InnoDB 在 DELETE 后只会把空闲页留在 .ibd 文件内部等待复用，
 * 永远不会把空间归还操作系统，磁盘占用呈单调递增，最终爆满。
 * 唯一能立即归还空间的做法是 DROP PARTITION（删除 .ibd 文件本身）。
 *
 * 本类负责三张日志表的 RANGE 分区生命周期：
 *   - ensurePartitions()  按天创建/补齐未来分区（DDL 不支持表达式，
 *                          边界必须是编译期常量，故在运行时拼接）。
 *   - dropExpiredPartitions() 丢弃已过期的整日分区。
 *
 * 分区表约束（MariaDB 硬性限制，违反即 ERROR 1506）：
 *   - 分区表不支持外键，级联删除必须由应用层显式完成；
 *   - 所有唯一键（含主键）都必须包含分区键，因此主键形如 (id, created)。
 *
 * 三张表使用完全一致的分区边界，保证 DROP 可以在同一时刻安全作用于全部表。
 */
final class LogPartitionManager
{
    /** 参与分区的日志表，顺序与分区维护顺序一致 */
    public const TABLES = ['logs', 'log_files', 'log_metadata'];

    /** 表名常量：与 TABLES 对应，供本类内部的查询拼接使用 */
    private const TABLE_LOGS = 'logs';
    private const TABLE_FILES = 'log_files';
    private const TABLE_METADATA = 'log_metadata';

    /** 兜底分区名：承接所有尚未被具体日期分区覆盖的行，写入永不失败 */
    private const FALLBACK = 'p_future';

    /**
     * 未来分区天数，必须与 scripts/sync_mariadb_events.php 中的 $lookaheadDays 一致。
     *
     * 该窗口必须显著大于 storageTime（默认 7 天），否则 TTL 内的日志会长期
     * 滞留在兜底分区里无法按日回收。
     */
    private const LOOKAHEAD_DAYS = 14;

    /** 单次清理允许丢弃的分区数上限，防止一次 DROP 过于激进 */
    private const MAX_DROP_PER_RUN = 60;

    /**
     * 计算未来分区边界列表（[今日 00:00, 今日+LOOKAHEAD 00:00)）。
     *
     * 只覆盖「今天起往后」：历史分区在事件安装时一次性铺好，随 TTL 到期被丢弃，
     * 运行期不再重建（重建会与幸存的较新分区重名，报 ERROR 1517）。
     *
     * 边界一律取 UTC 整日，保证三张表与存储过程使用完全一致的切分点；分区名
     * 由边界推导，三表同名，便于逐表同步维护。
     *
     * @return array<int, array{index:int, name:string, lessThan:int}>
     */
    public static function plannedPartitions(?int $now = null): array
    {
        $now ??= time();
        $dayStart = (int) (floor($now / 86400) * 86400);

        $partitions = [];
        for ($i = 0; $i < self::LOOKAHEAD_DAYS; $i++) {
            $start = $dayStart + ($i * 86400);
            $partitions[] = [
                'index' => $i,
                'name' => self::partitionName($start),
                'lessThan' => $start + 86400,
            ];
        }

        return $partitions;
    }

    /**
     * 分区名：p + 日期（UTC），便于按名字直接定位某个整日的分区。
     */
    public static function partitionName(int $boundary): string
    {
        return 'p' . gmdate('Ymd', $boundary);
    }

    /**
     * 补齐缺失的日期分区。
     *
     * 已存在的分区一律跳过；缺失的分区通过 REORGANIZE 从兜底分区切分，
     * 这样可以保留兜底分区里已存在的历史行，不会因新增分区而移动或丢失数据。
     *
     * 只补「今天起往后」的未来分区，绝不重建历史窗口。历史分区由事件安装时
     * 一次性铺好（日历边界是编译期常量，运行期无法安全重建），随 TTL 到期被
     * 正常丢弃；此处若试图重建历史窗口，会与幸存的较新分区重名，报
     * ERROR 1517，并形成「丢弃→重建→冲突」的死循环。
     *
     * 滞留在 p_future 中的过期历史数据由 cleanupStrays() 按行回收：兜底分区
     * 无法按子区间 DROP，这部分数据量通常极小（仅分区维护中断期间的写入）。
     *
     * @return int 新建的分区数（按表计）
     */
    public static function ensurePartitions(?int $now = null): int
    {
        $now ??= time();
        $created = 0;

        foreach (self::TABLES as $table) {
            $existing = self::existingPartitions()[$table] ?? [];
            foreach (self::plannedPartitions($now) as $partition) {
                if (isset($existing[$partition['name']])) {
                    continue;
                }
                self::reorganizeFallback($table, $partition['name'], $partition['lessThan']);
                $created++;
            }
        }

        return $created;
    }

    /**
     * 按行回收滞留在兜底分区中的过期数据。
     *
     * 兜底分区无法按子区间 DROP，只能逐行删除。这批数据通常极少：只有分区
     * 维护中断（磁盘满 / ALTER 失败）期间写入的过期日志才会滞留于此。真正
     * 的空间大头由 DROP PARTITION 回收，不受此路径影响。
     *
     * 分区表无外键，级联删除必须显式完成，且顺序为「先子表后父表」。
     *
     * @return int 删除的父表行数
     */
    public static function cleanupStrays(int $expireBefore, int $limit = 500): int
    {
        try {
            return Db::transaction(function () use ($expireBefore, $limit) {
                $expiredIds = Db::table(self::TABLE_LOGS)
                    ->where('created', '<', $expireBefore)
                    ->limit($limit)
                    ->pluck('id')
                    ->all();

                if (empty($expiredIds)) {
                    return 0;
                }

                Db::table(self::TABLE_METADATA)->whereIn('log_id', $expiredIds)->delete();
                Db::table(self::TABLE_FILES)->whereIn('log_id', $expiredIds)->delete();

                return Db::table(self::TABLE_LOGS)->whereIn('id', $expiredIds)->delete();
            });
        } catch (\Throwable $e) {
            \App\Syslog::error('LogPartitionManager', 'stray cleanup failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 丢弃覆盖范围已完全早于过期时刻的分区。
     *
     * 只处理边界严格小于 expireBefore 的分区：落在边界当天或之后的分区
     * 仍可能含有未过期数据，绝不能丢弃。
     *
     * @return int 实际丢弃的分区数（按表计）
     */
    public static function dropExpiredPartitions(int $expireBefore): int
    {
        $dropped = 0;
        $limit = self::MAX_DROP_PER_RUN;
        $existing = self::existingPartitions();

        foreach (self::TABLES as $table) {
            foreach (self::droppablePartitionNames($existing[$table] ?? [], $expireBefore) as $name) {
                if ($limit-- <= 0) {
                    return $dropped;
                }
                self::dropPartition($table, $name);
                $dropped++;
            }
        }

        return $dropped;
    }

    /**
     * 兜底分区必须始终存在，否则超出最后一个边界的写入会以
     * 「Table has no partition for value」直接失败。
     */
    public static function ensureFallbackPartition(): void
    {
        $existing = self::existingPartitions();

        foreach (self::TABLES as $table) {
            if (isset($existing[$table][self::FALLBACK])) {
                continue;
            }
            try {
                Db::statement("ALTER TABLE `{$table}` ADD PARTITION (PARTITION `" . self::FALLBACK . "` VALUES LESS THAN (MAXVALUE))");
            } catch (\Throwable $e) {
                \App\Syslog::error('LogPartitionManager', "failed to add fallback partition on {$table}: " . $e->getMessage());
            }
        }
    }

    /**
     * @return array<string, array<string, int>> 表名 => 分区名 => 边界值
     */
    private static function existingPartitions(): array
    {
        $rows = Db::select(
            'SELECT TABLE_NAME as t, PARTITION_NAME as p, PARTITION_DESCRIPTION as d
             FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND PARTITION_NAME IS NOT NULL
               AND TABLE_NAME IN ("logs","log_files","log_metadata")'
        );

        $result = [];
        foreach ($rows as $row) {
            $table = (string) $row->t;
            $description = strtoupper(trim((string) $row->d));
            // MAXVALUE 不是数值边界，记为 PHP_INT_MAX 表示「无上界」
            $result[$table][(string) $row->p] = ($description === 'MAXVALUE') ? PHP_INT_MAX : (int) $description;
        }

        return $result;
    }

    private static function reorganizeFallback(string $table, string $partitionName, int $lessThan): void
    {
        try {
            // REORGANIZE 会把边界内的行从兜底分区切走，边界外的行留在兜底分区，
            // 因此这一操作对已有数据是安全的（不会像 DROP+ADD 那样丢数据）。
            Db::statement(
                "ALTER TABLE `{$table}` REORGANIZE PARTITION `" . self::FALLBACK . "` INTO ("
                . "PARTITION `{$partitionName}` VALUES LESS THAN ({$lessThan}), "
                . 'PARTITION `' . self::FALLBACK . '` VALUES LESS THAN (MAXVALUE))'
            );
        } catch (\Throwable $e) {
            \App\Syslog::error('LogPartitionManager', "failed to reorganize {$table} for {$partitionName}: " . $e->getMessage());
        }
    }

    /**
     * @param array<string, int> $partitions 表的分区名 => 上界
     * @return string[] 可安全丢弃的分区名（已按边界升序）
     */
    private static function droppablePartitionNames(array $partitions, int $expireBefore): array
    {
        $candidates = [];
        foreach ($partitions as $name => $boundary) {
            if ($name === self::FALLBACK) {
                // 兜底分区承载所有未覆盖的行，任何情况下都不能丢弃
                continue;
            }
            if ($boundary <= $expireBefore) {
                $candidates[$name] = $boundary;
            }
        }

        asort($candidates);

        return array_keys($candidates);
    }

    private static function dropPartition(string $table, string $partitionName): void
    {
        try {
            Db::statement("ALTER TABLE `{$table}` DROP PARTITION `{$partitionName}`");
        } catch (\Throwable $e) {
            \App\Syslog::error('LogPartitionManager', "failed to drop {$table}.{$partitionName}: " . $e->getMessage());
        }
    }
}