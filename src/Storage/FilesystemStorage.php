<?php

namespace Storage;

use Data\MetadataEntry;
use Data\Token;

class FilesystemStorage implements StorageInterface
{
    public static function Put(string $data, ?Token $token = null, array $metadata = [], ?string $source = null): ?\Id
    {
        $config = \Config::Get("filesystem");
        $basePath = CORE_PATH . $config['path'];

        if (!is_writable($basePath)) {
            throw new \Exception("Filesystem storage driver could not write to " . $basePath . ". Please check if the directory exists and is writable.");
        }

        $id = new \Id();
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

        file_put_contents($basePath . $id->getRaw(), json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $id;
    }

    public static function Get(\Id $id, bool $includeContent = true): ?array
    {
        $config = \Config::Get("filesystem");
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
            'created' => $document['created'] ?? null,
        ];
    }

    public static function Renew(\Id $id): bool
    {
        $config = \Config::Get("filesystem");
        $basePath = CORE_PATH . $config['path'];
        $path = $basePath . $id->getRaw();

        if (!file_exists($path)) {
            return false;
        }

        return touch($path);
    }

    public static function Delete(\Id $id): bool
    {
        $config = \Config::Get("filesystem");
        $basePath = CORE_PATH . $config['path'];
        $path = $basePath . $id->getRaw();

        if (!file_exists($path)) {
            return false;
        }

        return unlink($path);
    }
}