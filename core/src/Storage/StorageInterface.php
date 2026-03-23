<?php

namespace Storage;

use Data\MetadataEntry;
use Data\Token;

interface StorageInterface
{
    /**
     * Put some data in the storage, returns the (new) id for the data
     *
     * @param string $data
     * @param Token|null $token
     * @param MetadataEntry[] $metadata
     * @param string|null $source
     * @return ?\Id ID or null
     */
    public static function Put(string $data, ?Token $token = null, array $metadata = [], ?string $source = null): ?\Id;

    /**
     * Get some data from the storage by id
     *
     * @param \Id $id
     * @param bool $includeContent
     * @return array|null Data array or null
     */
    public static function Get(\Id $id, bool $includeContent = true): ?array;

    /**
     * Renew the data to reset the time to live
     *
     * @param \Id $id
     * @return bool Success
     */
    public static function Renew(\Id $id): bool;

    /**
     * Delete data from the storage by id
     *
     * @param \Id $id
     * @return bool Success
     */
    public static function Delete(\Id $id): bool;
}