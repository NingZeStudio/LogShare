<?php

namespace App\Storage;

use App\Data\MetadataEntry;
use App\Data\Token;

class FilesystemStorage implements StorageInterface
{
    /** 单次 CleanupExpired 最多检查的文件数，限制请求路径内的清理工作量 */
    private const CLEANUP_BATCH = 500;

    public static function Put(string $data, ?Token $token = null, array $metadata = [], ?string $source = null, ?array $files = null): ?\App\Id
    {
        $config = \App\Config::Get("filesystem");
        $basePath = CORE_PATH . $config['path'];

        if (!is_writable($basePath)) {
            throw new \Exception("Filesystem storage driver could not write to " . $basePath . ". Please check if the directory exists and is writable.");
        }

        $id = new \App\Id();
        $id->setStorage("f");

        // 用 O_EXCL 语义（fopen 'x'）原子占位，防止并发下 rename 静默覆盖已有日志
        $path = $basePath . $id->getRaw();
        do {
            $id->regenerate();
            $path = $basePath . $id->getRaw();
            $placeholder = @fopen($path, 'x');
        } while ($placeholder === false);
        fclose($placeholder);

        $document = [
            'data' => $data,
            'created' => time(),
        ];

        if ($token !== null) {
            $document['token'] = $token->get();
        }

        if (!empty($metadata)) {
            $document['metadata'] = array_map(fn($entry) => $entry->jsonSerialize(), $metadata);
        }

        if ($source !== null) {
            $document['source'] = substr($source, 0, 64);
        }

        if (!empty($files)) {
            $document['files'] = array_values(array_map(
                fn($file) => [
                    'name' => $file['name'],
                    'data' => $file['data'],
                    'size' => strlen($file['data']),
                ],
                $files
            ));
        }

        try {
            self::writeAtomically($path, $document);
        } catch (\Throwable $e) {
            // 占位文件已存在，写正文失败时清干净，避免留下空文件
            @unlink($path);
            @unlink($path . '.meta.json');
            throw $e;
        }
        self::writeAtomically($path . '.meta.json', ['created' => $document['created']]);
        return $id;
    }

    public static function Get(\App\Id $id, bool $includeContent = true): ?array
    {
        $config = \App\Config::Get("filesystem");
        $basePath = CORE_PATH . $config['path'];

        if (!file_exists($basePath . $id->getRaw())) {
            return null;
        }

        $content = file_get_contents($basePath . $id->getRaw());
        if ($content === false) {
            return null;
        }

        $document = json_decode($content, true);
        if ($document === null || !is_array($document)) {
            return null;
        }

        return [
            'data' => $document['data'] ?? null,
            'token' => $document['token'] ?? null,
            'metadata' => $document['metadata'] ?? [],
            'source' => $document['source'] ?? null,
            'created' => self::readCreated($basePath . $id->getRaw(), $document),
            // 与 MariaDbStorage 对称：includeContent=false 仅剥离附加文件内容
            'files' => self::normalizeFiles($document['files'] ?? [], $includeContent),
        ];
    }

    private static function readCreated(string $path, ?array $document): ?int
    {
        $meta = $path . '.meta.json';
        if (is_file($meta)) {
            $value = json_decode((string) file_get_contents($meta), true);
            if (is_array($value) && isset($value['created'])) {
                return (int) $value['created'];
            }
        }
        return isset($document['created']) ? (int) $document['created'] : null;
    }

    private static function writeAtomically(string $path, array $document): bool
    {
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new \Exception('Failed to write log file.');
        }
        return true;
    }

    public static function Renew(\App\Id $id): bool
    {
        $config = \App\Config::Get("filesystem");
        $basePath = CORE_PATH . $config['path'];
        $path = $basePath . $id->getRaw();

        if (!file_exists($path)) {
            return false;
        }

        $metaPath = $path . '.meta.json';
        $metadata = ['created' => time()];
        if (file_exists($metaPath)) {
            $content = file_get_contents($metaPath);
            $metadata = $content === false ? [] : json_decode($content, true);
            if (!is_array($metadata)) {
                $metadata = [];
            }
        }
        $metadata['created'] = time();

        return self::writeAtomically($metaPath, $metadata);
    }

    /**
     * Delete expired log files. A document's `created` timestamp is resolved
     * the same way as in `Get()` (`.meta.json` first — written/updated by
     * `Renew()` — then the embedded field, then file mtime), so renewed logs
     * are not treated as expired. Processes at most self::CLEANUP_BATCH files
     * per call to bound the work done inside a request.
     *
     * @return int Number of deleted files
     */
    public static function CleanupExpired(): int
    {
        $config = \App\Config::Get("filesystem");
        $basePath = CORE_PATH . $config['path'];

        $storageConfig = \App\Config::Get('storage');
        $storageTime = (int) ($storageConfig['storageTime'] ?? (7 * 24 * 60 * 60));
        $now = time();
        $deleted = 0;

        if (!is_dir($basePath)) {
            return 0;
        }

        $files = glob($basePath . '*') ?: [];
        foreach (array_slice($files, 0, self::CLEANUP_BATCH) as $file) {
            if (!is_file($file)) {
                continue;
            }

            $content = @file_get_contents($file);
            $document = $content !== false ? json_decode($content, true) : null;
            $created = self::readCreated($file, is_array($document) ? $document : null)
                ?? @filemtime($file);

            if ($created > 0 && $created + $storageTime < $now) {
                @unlink($file);
                @unlink($file . '.meta.json');
                $deleted++;
            }
        }

        return $deleted;
    }

    public static function Delete(\App\Id $id): bool
    {
        $config = \App\Config::Get("filesystem");
        $basePath = CORE_PATH . $config['path'];
        $path = $basePath . $id->getRaw();

        if (!file_exists($path)) {
            return false;
        }

        $deleted = unlink($path);
        // 主文档删除成功时同步清理伴随的元数据文件，避免残留孤儿
        @unlink($path . '.meta.json');
        return $deleted;
    }

    /**
     * Normalize file entries; drops `data` when $includeContent is false.
     */
    private static function normalizeFiles(array $files, bool $includeContent): array
    {
        $result = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $entry = [
                'name' => $file['name'] ?? '',
                'size' => isset($file['size']) ? (int) $file['size'] : strlen($file['data'] ?? ''),
            ];
            if ($includeContent) {
                $entry['data'] = $file['data'] ?? '';
            }
            $result[] = $entry;
        }
        return $result;
    }
}
