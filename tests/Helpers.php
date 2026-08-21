<?php

function makeZip(array $entries): string {
    $tmp = CORE_PATH . '/tmp/test_' . uniqid() . '.zip';
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $data = file_get_contents($tmp);
    unlink($tmp);
    return $data;
}

/**
 * 启动一个后台 php -S mock 服务器并等待就绪。
 *
 * 在受限环境（shell_exec 被禁用 / php 不在 PATH / 端口全部冲突）下优雅返回
 * null，由调用方跳过依赖该 mock 的测试，避免整个测试文件硬失败。
 *
 * @return array{pid: int, base: string}|null
 */
function startMockServer(string $routerFile, int $portRangeStart, int $portRangeSize): ?array {
    if (!function_exists('shell_exec') || !function_exists('fsockopen')) {
        return null;
    }

    $port = $portRangeStart + mt_rand(1, $portRangeSize);
    $cmd = sprintf(
        'php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!',
        $port,
        escapeshellarg($routerFile)
    );
    $output = @shell_exec($cmd);
    $pid = (int) trim((string) $output);

    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($fp) {
            fclose($fp);
            $ready = true;
            break;
        }
        usleep(100000);
    }

    if (!$ready) {
        if ($pid > 0 && function_exists('posix_kill')) {
            @posix_kill($pid, 15);
        }
        return null;
    }

    return ['pid' => $pid, 'base' => 'http://127.0.0.1:' . $port];
}

/**
 * 若 mock 服务器不可用则跳过当前测试。
 */
function skipWithoutMockServer(?string $baseUrl): void {
    if ($baseUrl === null) {
        // 抛 Skip 异常，交由 Pest/PHPUnit 记为 skipped
        throw new \PHPUnit\Framework\SkippedWithMessageException('Mock server unavailable');
    }
}
