-- 由 scripts/sync_mariadb_events.php 生成，请勿手工编辑。
--
-- 清理策略：按 created 的天级 RANGE 分区直接 DROP PARTITION，而非 DELETE 逐行删除。
-- InnoDB 的 DELETE 只会在 .ibd 文件内部留下永远不会被回收的空洞，磁盘占用只增不减
-- （实测：删除 171MB 数据后磁盘占用纹丝不动）；只有丢弃分区才能把磁盘空间真正
-- 归还操作系统。该操作是元数据级的瞬时动作，不产生逐行扫描与 purge 压力。
--
-- 三步职责：
--   1. 增量补齐未来分区，保证新写入始终有分区可用；
--   2. 丢弃完全早于过期时刻的日分区，真正归还磁盘空间；
--   3. 兜底 DELETE 回收滞留在 p_future 中的过期行（分区维护中断期间写入的），
--      以及尚未获得具名分区的历史数据。

-- 一次性建立覆盖 [安装时刻 - TTL, 今天] 的历史日分区。
--
-- 这些分区是空间回收能否生效的前提：TTL 内的每一条日志都必须落在具名分区里，
-- 否则它会滞留在兜底分区 p_future 中，而 p_future 无法按子区间 DROP，最终
-- 变成永不归还磁盘的死数据。
--
-- 边界在生成本文件时固化为具体时间戳（DDL 不接受表达式），因此本段仅在事件
-- 安装时执行一次；安装之后再运行本文件是幂等的（IF NOT EXISTS 保护）。
DROP PROCEDURE IF EXISTS logshare_install_partitions;
DELIMITER $$
CREATE PROCEDURE logshare_install_partitions()
BEGIN
    DECLARE v_t VARCHAR(64);
    DECLARE v_done INT DEFAULT 0;

    DECLARE cur CURSOR FOR SELECT t FROM (
        SELECT 'logs' AS t UNION ALL SELECT 'log_files' UNION ALL SELECT 'log_metadata'
    ) x;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN cur;
    t_loop: LOOP
        FETCH cur INTO v_t;
        IF v_done = 1 THEN
            LEAVE t_loop;
        END IF;

        -- 以「最靠近今天的历史分区」为哨兵判断整段历史区间是否已建过。
        -- 不用计数：过期分区会被正常丢弃，任何基于数量的判断都会在丢弃之后
        -- 误判为「未建过」，进而触发重名冲突。
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.PARTITIONS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = v_t
              AND PARTITION_NAME = 'p20261009'
        ) THEN
            SET @ddl = CONCAT(
                'ALTER TABLE `', v_t, '` REORGANIZE PARTITION `p_future` INTO (',
                'PARTITION `p20261002` VALUES LESS THAN (1790985600), PARTITION `p20261003` VALUES LESS THAN (1791072000), PARTITION `p20261004` VALUES LESS THAN (1791158400), PARTITION `p20261005` VALUES LESS THAN (1791244800), PARTITION `p20261006` VALUES LESS THAN (1791331200), PARTITION `p20261007` VALUES LESS THAN (1791417600), PARTITION `p20261008` VALUES LESS THAN (1791504000), PARTITION `p20261009` VALUES LESS THAN (1791590400), PARTITION `p_future` VALUES LESS THAN (MAXVALUE))'
            );
            PREPARE stmt FROM @ddl;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END IF;
    END LOOP;
    CLOSE cur;
END$$
DELIMITER ;

CALL logshare_install_partitions();
DROP PROCEDURE IF EXISTS logshare_install_partitions;

DROP PROCEDURE IF EXISTS logshare_cleanup_expired;
DELIMITER $$
CREATE PROCEDURE logshare_cleanup_expired(IN p_ttl INT)
BEGIN
    -- v_cutoff 必须声明为 SIGNED：information_schema.PARTITIONS.PARTITION_DESCRIPTION
    -- 是无符号列，若与无符号值比较，负数 cutoff 会被提升为巨大无符号数，导致
    -- 「没有任何分区可丢弃」且不报错——清理静默失效，磁盘持续膨胀。
    DECLARE v_cutoff BIGINT SIGNED DEFAULT UNIX_TIMESTAMP() - p_ttl;
    DECLARE v_table VARCHAR(64);
    DECLARE v_name VARCHAR(64);
    DECLARE v_spec TEXT;
    DECLARE v_have INT DEFAULT 0;
    DECLARE v_want INT DEFAULT 0;
    DECLARE v_drop TEXT;
    DECLARE v_done INT DEFAULT 0;

    -- 1) 增量补齐未来分区。
    --    只补「今天起往后、尚未建立」的日子，逐日判断存在性。历史分区绝不在此
    --    重建：过期分区会被步骤 2 正常丢弃，若此处试图重建历史窗口，会与幸存的
    --    较新分区重名（ERROR 1517），形成「丢弃→重建→冲突」的死循环。
    --    REORGANIZE 会把边界内的行从 p_future 切走、边界外的行留在 p_future，
    --    因此对已存在的数据是安全的（不会像 DROP + ADD 那样丢数据）。
    DECLARE cur CURSOR FOR SELECT t FROM (
        SELECT 'logs' AS t
        UNION ALL SELECT 'log_files'
        UNION ALL SELECT 'log_metadata'
    ) x;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN cur;
    tbl_loop: LOOP
        FETCH cur INTO v_table;
        IF v_done = 1 THEN
            LEAVE tbl_loop;
        END IF;

        SET v_spec = NULL;
        SELECT GROUP_CONCAT(
            CONCAT('PARTITION `p', DATE_FORMAT(FROM_UNIXTIME(d.boundary), '%Y%m%d'),
                   '` VALUES LESS THAN (', d.boundary + 86400, ')')
            ORDER BY d.boundary
        )
        INTO v_spec
        FROM (
            SELECT ((UNIX_TIMESTAMP() DIV 86400) + s.k) * 86400 AS boundary
            FROM (SELECT 0 AS k UNION ALL SELECT 1 AS k UNION ALL SELECT 2 AS k UNION ALL SELECT 3 AS k UNION ALL SELECT 4 AS k UNION ALL SELECT 5 AS k UNION ALL SELECT 6 AS k UNION ALL SELECT 7 AS k UNION ALL SELECT 8 AS k UNION ALL SELECT 9 AS k UNION ALL SELECT 10 AS k UNION ALL SELECT 11 AS k UNION ALL SELECT 12 AS k UNION ALL SELECT 13 AS k) s
        ) d
        WHERE NOT EXISTS (
            SELECT 1 FROM information_schema.PARTITIONS xp
            WHERE xp.TABLE_SCHEMA = DATABASE()
              AND xp.TABLE_NAME = v_table
              AND xp.PARTITION_DESCRIPTION = CAST(d.boundary + 86400 AS CHAR)
        );

        IF v_spec IS NOT NULL THEN
            SET @ddl = CONCAT(
                'ALTER TABLE `', v_table, '` REORGANIZE PARTITION `p_future` INTO (',
                v_spec, ', PARTITION `p_future` VALUES LESS THAN (MAXVALUE))'
            );
            PREPARE stmt FROM @ddl;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END IF;
    END LOOP;
    CLOSE cur;

    -- 2) 丢弃完全早于过期时刻的分区。只处理具名的日分区，
    --    p_future 兜底分区承载所有未覆盖的行，任何情况下都不能丢弃。
    --
    --    ⚠ 判定必须以「分区名」而非「各表自己的边界」为准：
    --    三张表的分区边界在运行期可能已经错位（某次清理只删掉了其中一张表
    --    的某个分区，随后又因陈旧视图中断）。此时若让每张表各自按
    --    PARTITION_DESCRIPTION <= cutoff 判定，同一个分区名会在 A 表被判为
    --    可丢、在 B 表被判为不可丢，分区集合从此永久错位。
    --
    --    因此这里取「三表中该分区名的最小上界」作为统一判据：只要任一表
    --    认为它已完全过期，三张表就一起丢，从而自愈既有的边界错位；
    --    反之若某表上界更大，则该分区在三表中都判为保留，不会丢半张表。
    --
    --    DROP 逐个执行：information_schema.PARTITIONS 在一次会话内可能返回
    --    陈旧视图（DDL 之后尤其明显），批量拼接会让整条 ALTER 语法失败
    --    （ERROR 1064）并作废该表本次清理。
    SET v_table = 'logs';
    WHILE v_table IS NOT NULL DO
        SET v_done = 0;
        DECLARE_PART: LOOP
            -- 上限保护：若 information_schema 返回陈旧视图导致分区始终「存在」，
            -- 无界循环会卡死 Event Scheduler，进而阻塞同库其它事件。
            SET v_done = v_done + 1;
            IF v_done > 400 THEN
                LEAVE DECLARE_PART;
            END IF;

            SELECT p.PARTITION_NAME INTO v_name
            FROM information_schema.PARTITIONS p
            WHERE p.TABLE_SCHEMA = DATABASE()
              AND p.TABLE_NAME = v_table
              AND p.PARTITION_NAME <> 'p_future'
              AND p.PARTITION_DESCRIPTION REGEXP '^[0-9]+$'
              AND (
                    SELECT MAX(CAST(q.PARTITION_DESCRIPTION AS SIGNED))
                    FROM information_schema.PARTITIONS q
                    WHERE q.TABLE_SCHEMA = DATABASE()
                      AND q.TABLE_NAME IN ('logs','log_files','log_metadata')
                      AND q.PARTITION_NAME = p.PARTITION_NAME
                      AND q.PARTITION_DESCRIPTION REGEXP '^[0-9]+$'
                  ) <= v_cutoff
            ORDER BY p.PARTITION_ORDINAL_POSITION
            LIMIT 1;

            IF v_name IS NULL THEN
                LEAVE DECLARE_PART;
            END IF;

            SET @ddl = CONCAT('ALTER TABLE `', v_table, '` DROP PARTITION `', v_name, '`');
            PREPARE stmt FROM @ddl;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END LOOP;

        SET v_table = CASE v_table
            WHEN 'logs' THEN 'log_files'
            WHEN 'log_files' THEN 'log_metadata'
            ELSE NULL
        END;
    END WHILE;

    -- 3) 回收 p_future 中早于过期时刻的行。
    --    兜底分区无法按子区间 DROP，只能逐行 DELETE。这批数据通常极少：
    --    只有分区维护中断（磁盘满 / ALTER 失败）期间写入的过期日志才会滞留
    --    在此。它们的量级不足以形成空洞，真正的大头已由步骤 2 的 DROP 回收。
    --    先删子表再删父表：分区表无外键，级联必须显式完成。
    DELETE FROM log_metadata WHERE created < v_cutoff AND log_id IN (SELECT id FROM (SELECT id, created FROM logs WHERE created < v_cutoff) AS expired);
    DELETE FROM log_files WHERE created < v_cutoff AND log_id IN (SELECT id FROM (SELECT id, created FROM logs WHERE created < v_cutoff) AS expired);
    DELETE FROM logs WHERE created < v_cutoff;
END$$
DELIMITER ;

DROP EVENT IF EXISTS cleanup_expired_logs;
CREATE EVENT cleanup_expired_logs
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP + INTERVAL 1 HOUR
DO CALL logshare_cleanup_expired(604800);
