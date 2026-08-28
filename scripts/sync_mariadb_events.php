<?php

declare(strict_types=1);

$configPath = dirname(__DIR__) . '/Config.inc.php';
$outputPath = dirname(__DIR__) . '/docker/mariadb-events.sql';

if (!is_file($configPath)) {
    fwrite(STDERR, "Config.inc.php not found\n");
    exit(1);
}

$config = require $configPath;
$storageTime = $config['storage']['storageTime'] ?? null;

if (!is_int($storageTime) && !is_float($storageTime) && !is_string($storageTime)) {
    fwrite(STDERR, "storage.storageTime is missing\n");
    exit(1);
}

$storageTime = (int) $storageTime;
if ($storageTime <= 0) {
    fwrite(STDERR, "storage.storageTime must be greater than zero\n");
    exit(1);
}

$sql = "DROP EVENT IF EXISTS cleanup_expired_logs;\n"
    . "CREATE EVENT cleanup_expired_logs\n"
    . "ON SCHEDULE EVERY 1 HOUR\n"
    . "STARTS CURRENT_TIMESTAMP + INTERVAL 1 HOUR\n"
    . "DO DELETE FROM logs\n"
    . "WHERE created < UNIX_TIMESTAMP() - {$storageTime};\n";

if (file_put_contents($outputPath, $sql) === false) {
    fwrite(STDERR, "Unable to write generated SQL\n");
    exit(1);
}

fwrite(STDOUT, "Generated MariaDB Event SQL with storage TTL {$storageTime} seconds\n");
