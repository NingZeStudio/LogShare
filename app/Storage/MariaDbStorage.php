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

    /** 单次 CleanupExpired 最多删除的日志数，限制请求路径内的清理工作量 */
    private const CLEANUP_BATCH = 500;

    public static function Put(string $data, ?Token $token = null, array $metadata = [], ?string $source = null, ?array $files = null): ?\App\Id
    {
        $id = new \App\Id();
        $id->setStorage('s');

        // 协程并发下 idExists 预检与 insert 之间存在 TOCTOU 窗口，唯一键冲突时
        // 重新生成 ID 重试（预检仍保留，用于消除绝大多数冲突）
        $maxAttempts = 5;
        for ($attempt = 0; ; $attempt++) {
            do {
                $id->regenerate();
            } while (self::idExists($id->getRaw()));

            try {
                Db::transaction(function () use ($id, $data, $token, $metadata, $source, $files) {
                    self::insertLog($id->getRaw(), $data, $token, $metadata, $source, $files);
                });
                break;
            } catch (\Throwable $e) {
                if ($attempt >= $maxAttempts - 1 || !self::isDuplicateKeyError($e)) {
                    throw $e;
                }
            }
        }

        return $id;
    }

    private static function insertLog(string $rawId, string $data, ?Token $token, array $metadata, ?string $source, ?array $files): void
    {
        $document = [
            'id' => $rawId,
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
                    'log_id' => $rawId,
                    'key' => $serialized['key'],
                    // 非字符串值统一 JSON 序列化后存储；读取端 decodeMetadataValue 会做对称还原
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
                    'log_id' => $rawId,
                    'name' => $file['name'],
                    'data' => $file['data'],
                    'size' => strlen($file['data']),
                ];
            }
            Db::table(self::TABLE_FILES)->insert($rows);
        }
    }

    /**
     * 判断异常是否为主键/唯一键冲突（并发下同 ID 竞争写入）。
     */
    private static function isDuplicateKeyError(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'Duplicate entry')
            || (string) $e->getCode() === '23000';
    }

    /**
     * 还原 metadata 值的原始类型：写入端对非字符串值做了 json_encode，
     * 读取端做对称解码，使 MariaDB 后端与 Filesystem 后端（jsonSerialize
     * 保留类型）行为一致。普通字符串解码必然失败而原样返回；恰为合法
     * JSON 标量（如 "true"）的用户原始字符串会被还原成对应类型，此歧义
     * 换取两端类型一致性。
     */
    private static function decodeMetadataValue(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
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
    {
        $log = Db::table(self::TABLE_LOGS)->where('id', $id->getRaw())->first();
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
                'value' => self::decodeMetadataValue($m->value),
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
     * `storage.storageTime`. Deletes at most self::CLEANUP_BATCH logs per
     * call to bound the work done inside a request; remaining expired rows
     * are picked up by subsequent calls.
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
                ->limit(self::CLEANUP_BATCH)
                ->pluck('id')
                ->all();

            if (empty($expiredIds)) {
                return 0;
            }

            Db::table(self::TABLE_METADATA)->whereIn('log_id', $expiredIds)->delete();
            Db::table(self::TABLE_FILES)->whereIn('log_id', $expiredIds)->delete();

            return Db::table(self::TABLE_LOGS)->whereIn('id', $expiredIds)->delete();
        });
    }
}
