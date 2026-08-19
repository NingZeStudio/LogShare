<?php

namespace Storage;

use Data\MetadataEntry;
use Data\Token;
use MongoDB\BSON\UTCDateTime;

class MongoStorage extends \Client\MongoDBClient implements StorageInterface
{
    protected const COLLECTION_NAME = "logs";

    /**
     * Put some data in the storage, returns the (new) id for the data
     *
     * @param string $data
     * @param Token|null $token
     * @param MetadataEntry[] $metadata
     * @param string|null $source
     * @return ?\Id ID or null
     */
    public static function Put(string $data, ?Token $token = null, array $metadata = [], ?string $source = null, ?array $files = null): ?\Id
    {
        $id = new \Id();
        $id->setStorage("m");

        do {
            $id->regenerate();
        } while (self::Get($id) !== null);

        $document = [
            "_id" => $id->getRaw(),
            "data" => $data,
            "created" => new UTCDateTime()
        ];

        if ($token !== null) {
            $document["token"] = $token->get();
        }

        if (!empty($metadata)) {
            $document["metadata"] = array_map(fn($entry) => $entry->jsonSerialize(), $metadata);
        }

        if ($source !== null) {
            $document["source"] = substr($source, 0, 64);
        }

        if (!empty($files)) {
            $document["files"] = array_values(array_map(
                fn($file) => [
                    'name' => $file['name'],
                    'data' => $file['data'],
                    'size' => strlen($file['data']),
                ],
                $files
            ));
        }

        self::getCollection()->insertOne($document);

        return $id;
    }

    /**
     * Get some data from the storage by id
     *
     * @param \Id $id
     * @param bool $includeContent
     * @return array|null Data array or null
     */
    public static function Get(\Id $id, bool $includeContent = true): ?array
    {
        $options = [];
        if (!$includeContent) {
            $options['projection'] = ['data' => 0, 'files.data' => 0];
        }

        $result = self::getCollection()->findOne(["_id" => $id->getRaw()], $options);

        if ($result === null) {
            // Check for legacy ID without the first character
            $result = self::getCollection()->findOne(["_id" => substr($id->getRaw(), 1)], $options);
        }

        if ($result === null) {
            return null;
        }

        $files = [];
        if (!empty($result->files)) {
            foreach ($result->files as $file) {
                if ($includeContent) {
                    $files[] = [
                        'name' => $file->name ?? '',
                        'data' => $file->data ?? '',
                        'size' => isset($file->size) ? (int) $file->size : strlen($file->data ?? ''),
                    ];
                } else {
                    $files[] = [
                        'name' => $file->name ?? '',
                        'size' => isset($file->size) ? (int) $file->size : strlen($file->data ?? ''),
                    ];
                }
            }
        }

        return [
            'data' => $result->data ?? null,
            'token' => $result->token ?? null,
            'metadata' => $result->metadata ?? [],
            'source' => $result->source ?? null,
            'created' => $result->created ?? null,
            'files' => $files,
        ];
    }

    /**
     * Renew the data to reset the time to live
     *
     * @param \Id $id
     * @return bool Success
     */
    public static function Renew(\Id $id): bool
    {
        $result = self::getCollection()->updateOne(
            ["_id" => $id->getRaw()], 
            ['$set' => ['created' => new UTCDateTime()]]
        );

        if ($result->getModifiedCount() === 0) {
            // Try legacy ID
            self::getCollection()->updateOne(
                ["_id" => substr($id->getRaw(), 1)], 
                ['$set' => ['created' => new UTCDateTime()]]
            );
        }

        return true;
    }

    /**
     * Delete data from the storage by id
     *
     * @param \Id $id
     * @return bool Success
     */
    public static function Delete(\Id $id): bool
    {
        $result = self::getCollection()->deleteOne(["_id" => $id->getRaw()]);

        if ($result->getDeletedCount() === 0) {
            // Check for legacy ID without the first character
            $result = self::getCollection()->deleteOne(["_id" => substr($id->getRaw(), 1)]);
            return $result->getDeletedCount() > 0;
        }

        return true;
    }

}
