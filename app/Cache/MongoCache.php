<?php

namespace App\Cache;

use App\Client\MongoDBClient;
use MongoDB\BSON\UTCDateTime;

class MongoCache extends MongoDBClient implements CacheInterface
{
    protected const COLLECTION_NAME = "cache";

    /**
     * @inheritDoc
     */
    public static function Set(string $key, string $value, ?int $duration = null)
    {
        $date = null;
        if ($duration) {
            $date = new UTCDateTime((time() + $duration) * 1000);
        }

        $update = [
            '$set' => [
                "data" => $value,
                "expires" => $date,
            ],
        ];

        self::getCollection()->updateOne(["_id" => $key], $update, ['upsert' => true]);
    }

    /**
     * @inheritDoc
     */
    public static function Get(string $key): ?string
    {
        return self::getCollection()->findOne(["_id" => $key])?->data;
    }

    /**
     * @inheritDoc
     */
    public static function Exists(string $key): bool
    {
        return self::getCollection()->findOne(["_id" => $key]) !== null;
    }

    /**
     * @inheritDoc
     */
    public static function Delete(string $key): bool
    {
        $result = self::getCollection()->deleteOne(["_id" => $key]);
        return $result->getDeletedCount() > 0;
    }
}