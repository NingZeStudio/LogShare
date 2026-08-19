<?php

namespace App\Client;

use MongoDB\Client;
use MongoDB\Collection;

class MongoDBClient
{
    /**
     * MongoDB Collection name
     */
    protected const COLLECTION_NAME = "logs";

    /**
     * @var null|Client
     */
    protected static ?Client $connection = null;

    /**
     * Connect to MongoDB
     */
    protected static function Connect()
    {
        if (self::$connection === null) {
            $config = \App\Config::Get("mongo");
            self::$connection = new Client($config['url'] ?? 'mongodb://mclogs-mongo/');
        }
    }

    /**
     * get the collection specified by {{@link COLLECTION_NAME}}
     * @return Collection
     */
    protected static function getCollection(): Collection
    {
        static::Connect();
        $config = \App\Config::Get("mongo");
        return self::$connection->{$config['database'] ?? 'mclogs'}->{static::COLLECTION_NAME};
    }

    /**
     * Ensure indexes exist
     *
     * Creates a TTL index on `created` so documents auto-expire
     * after the configured storage time. The `Renew()` operation
     * updates `created` to now, resetting the TTL.
     *
     * @return void
     */
    public static function ensureIndexes(): void
    {
        $collection = self::getCollection();
        $config = \App\Config::Get('storage');
        $expireAfterSeconds = $config['storageTime'] ?? (7 * 24 * 60 * 60);
        $collection->createIndex(
            ['created' => 1],
            ['expireAfterSeconds' => $expireAfterSeconds]
        );
    }
}