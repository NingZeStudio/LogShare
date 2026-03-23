<?php

namespace Client;

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
            $config = \Config::Get("mongo");
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
        $config = \Config::Get("mongo");
        return self::$connection->{$config['database'] ?? 'mclogs'}->{static::COLLECTION_NAME};
    }

    /**
     * Ensure indexes exist
     *
     * @return void
     */
    public static function ensureIndexes(): void
    {
        $collection = self::getCollection();
        $collection->createIndex(['expires' => 1], ['expireAfterSeconds' => 0]);
    }
}