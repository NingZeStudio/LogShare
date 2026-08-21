-- LogShare MariaDB 表结构（首次启动时由 mariadb docker-entrypoint-initdb.d 自动执行）
CREATE TABLE IF NOT EXISTS logs (
    id CHAR(6) PRIMARY KEY,
    data LONGTEXT NOT NULL,
    token VARCHAR(64) NULL,
    source VARCHAR(64) NULL,
    created INT UNSIGNED NOT NULL,
    KEY idx_created (created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS log_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    log_id CHAR(6) NOT NULL,
    name VARCHAR(512) NOT NULL,
    data LONGTEXT NOT NULL,
    size INT UNSIGNED NOT NULL,
    KEY idx_log_files_log_id (log_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS log_metadata (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    log_id CHAR(6) NOT NULL,
    `key` VARCHAR(64) NOT NULL,
    `value` TEXT NULL,
    `label` VARCHAR(128) NULL,
    `visible` TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_log_metadata_log_id (log_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
