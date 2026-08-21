<?php

namespace App\Storage;

use App\Data\MetadataEntry;
use App\Data\Token;
use Hyperf\DbConnection\Db;

class MariaDbStorage implements StorageInterface
{
    protected const TABLE_LOGS = 'logs';
    protected const TABLE_FILES = 'log_files';
    protected const TABLE_METADATA = 'log_metadata';

    public static function Put(string $data, ?Token $token = null, array $metadata = [], ?string $source = null, ?array $files = null): ?\App\Id
    {
        $id = new \App\Id();
        $id->setStorage('s');

        do {
            $id->regenerate();
        } while (self::idExists($id->getRaw()));

        Db::transaction(function () use ($id, $data, $token, $metadata, $source, $files) {
            $document = [
                'id' => $id->getRaw(),
                'data' => $data,
                'created' => time(),
            ];

            if ($token !== null) {
                $document['token'] = $token->get();
            }

            if ($source !== null) {
                $document['source'] = substr($source, 0, 64);
            }

            Db::table(self::TABLE_LOGS)->insert($document);

            if (!empty($metadata)) {
                $rows = [];
                foreach ($metadata as $entry) {
                    $serialized = $entry->jsonSerialize();
                    if (empty($serialized['key'])) {
                        continue;
                    }
                    $value = $serialized['value'] ?? null;
                    $rows[] = [
                        'log_id' => $id->getRaw(),
                        'key' => $serialized['key'],
                        'value' => is_string($value) ? $value : (is_null($value) ? null : json_encode($value, JSON_UNESCAPED_UNICODE)),
                        'label' => $serialized['label'] ?? null,
                        'visible' => (int) ($serialized['visible'] ?? true),
                    ];
                }
                if (!empty($rows)) {
                    Db::table(self::TABLE_METADATA)->insert($rows);
                }
            }

            if (!empty($files)) {
                $rows = [];
                foreach ($files as $file) {
                    $rows[] = [
                        'log_id' => $id->getRaw(),
                        'name' => $file['name'],
                        'data' => $file['data'],
                        'size' => strlen($file['data']),
                    ];
                }
                Db::table(self::TABLE_FILES)->insert($rows);
            }
        });

        return $id;
    }

    /**
     * 轻量存在性检查（仅查 logs 表主键，避免 Get() 连带拉取 files/metadata）。
     *
     * @param string $rawId
     * @return bool
     */
    private static function idExists(string $rawId): bool
    {
        return Db::table(self::TABLE_LOGS)->where('id', $rawId)->exists();
    }

    public static function Get(\App\Id $id, bool $includeContent = true): ?array
    {        $log = Db::table(self::TABLE_LOGS)->where('id', $id->getRaw())->first();
        if ($log === null) {
            return null;
        }

        $files = [];
        $fileQuery = Db::table(self::TABLE_FILES)->where('log_id', $id->getRaw());
        if (!$includeContent) {
            $fileQuery->select(['name', 'size']);
        }
        foreach ($fileQuery->get() as $file) {
            $entry = [
                'name' => $file->name,
                'size' => (int) $file->size,
            ];
            if ($includeContent) {
                $entry['data'] = $file->data;
            }
            $files[] = $entry;
        }

        $metadata = [];
        foreach (Db::table(self::TABLE_METADATA)->where('log_id', $id->getRaw())->get() as $m) {
            $metadata[] = [
                'key' => $m->key,
                'value' => $m->value,
                'label' => $m->label,
                'visible' => (bool) $m->visible,
            ];
        }

        return [
            'data' => $log->data,
            'token' => $log->token,
            'metadata' => $metadata,
            'source' => $log->source,
            'created' => (int) $log->created,
            'files' => $files,
        ];
    }

    public static function Renew(\App\Id $id): bool
    {
        return Db::table(self::TABLE_LOGS)
            ->where('id', $id->getRaw())
            ->update(['created' => time()]) > 0;
    }

    public static function Delete(\App\Id $id): bool
    {
        return Db::transaction(function () use ($id) {
            Db::table(self::TABLE_METADATA)->where('log_id', $id->getRaw())->delete();
            Db::table(self::TABLE_FILES)->where('log_id', $id->getRaw())->delete();

            return Db::table(self::TABLE_LOGS)->where('id', $id->getRaw())->delete() > 0;
        });
    }

    /**
     * Delete expired logs whose `created` timestamp is older than
     * `storage.storageTime`. Mirrors the MongoDB TTL index behaviour.
     *
     * @return int Number of deleted logs
     */
    public static function CleanupExpired(): int
    {
        $config = \App\Config::Get('storage');
        $storageTime = (int) ($config['storageTime'] ?? (7 * 24 * 60 * 60));
        $expireBefore = time() - $storageTime;

        return Db::transaction(function () use ($expireBefore) {
            $expiredIds = Db::table(self::TABLE_LOGS)
                ->where('created', '<', $expireBefore)
                ->pluck('id')
                ->all();

            foreach ($expiredIds as $logId) {
                Db::table(self::TABLE_METADATA)->where('log_id', $logId)->delete();
                Db::table(self::TABLE_FILES)->where('log_id', $logId)->delete();
            }

            return Db::table(self::TABLE_LOGS)->where('created', '<', $expireBefore)->delete();
        });
    }
}
