<?php

namespace App\Storage;

use App\Data\MetadataEntry;
use App\Data\Token;

class FilesystemStorage implements StorageInterface
{
    public static function Put(string $data, ?Token $token = null, array $metadata = [], ?string $source = null, ?array $files = null): ?\App\Id
    {
        $config = \App\Config::Get("filesystem");
        $basePath = CORE_PATH . $config['path'];

        if (!is_writable($basePath)) {
            throw new \Exception("Filesystem storage driver could not write to " . $basePath . ". Please check if the directory exists and is writable.");
        }

        $id = new \App\Id();
        $id->setStorage("f");

        do {
            $id->regenerate();
        } while (file_exists($basePath . $id->getRaw()));

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

        if (file_put_contents($basePath . $id->getRaw(), json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            throw new \Exception("Failed to write log file.");
        }
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

        $files = [];
        if (!empty($document['files'])) {
            foreach ($document['files'] as $file) {
                if ($includeContent) {
                    $files[] = [
                        'name' => $file['name'] ?? '',
                        'data' => $file['data'] ?? '',
                        'size' => isset($file['size']) ? (int) $file['size'] : strlen($file['data'] ?? ''),
                    ];
                } else {
                    $files[] = [
                        'name' => $file['name'] ?? '',
                        'size' => isset($file['size']) ? (int) $file['size'] : strlen($file['data'] ?? ''),
                    ];
                }
            }
        }

        return [
            'data' => $document['data'] ?? null,
            'token' => $document['token'] ?? null,
            'metadata' => $document['metadata'] ?? [],
            'source' => $document['source'] ?? null,
            'created' => $document['created'] ?? null,
            'files' => $files,
        ];
    }

    public static function Renew(\App\Id $id): bool
    {
        $config = \App\Config::Get("filesystem");
        $basePath = CORE_PATH . $config['path'];
        $path = $basePath . $id->getRaw();

        if (!file_exists($path)) {
            return false;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return false;
        }

        $document = json_decode($content, true);
        if (!is_array($document)) {
            return false;
        }

        // Reset created to now, resetting the storage TTL.
        $document['created'] = time();

        return file_put_contents($path, json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
    }

    /**
     * Delete expired log files. Files whose `created` timestamp is older than
     * `storage.storageTime` are removed. Falls back to file mtime for documents
     * without a `created` field.
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

        foreach (glob($basePath . '*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $content = @file_get_contents($file);
            $document = $content !== false ? json_decode($content, true) : null;
            $created = is_array($document) && isset($document['created'])
                ? (int) $document['created']
                : @filemtime($file);

            if ($created > 0 && $created + $storageTime < $now) {
                @unlink($file);
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

        return unlink($path);
    }
}