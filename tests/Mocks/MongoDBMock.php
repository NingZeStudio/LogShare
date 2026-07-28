<?php

namespace Tests\Mocks;

class MongoDBMock
{
    private static array $collections = [];

    public function __construct(...$args) {}

    public function executeBulkWrite(string $namespace, $bulkWrite): \Tests\Mocks\MongoWriteResult
    {
        return new \Tests\Mocks\MongoWriteResult(true, 1, ['inserted' => 'mock-id']);
    }

    public function executeQuery(string $namespace, $query): \Tests\Mocks\MongoCursor
    {
        return new \Tests\Mocks\MongoCursor([]);
    }

    public function executeCommand(string $db, $command): \Tests\Mocks\MongoCommandResult
    {
        return new \Tests\Mocks\MongoCommandResult(['ok' => 1]);
    }

    public static function setCollectionData(string $namespace, array $data): void
    {
        self::$collections[$namespace] = $data;
    }

    public static function getCollectionData(string $namespace): array
    {
        return self::$collections[$namespace] ?? [];
    }

    public static function reset(): void
    {
        self::$collections = [];
    }
}

class MongoWriteResult
{
    public function __construct(
        public bool $acknowledged,
        public int $insertedCount,
        public array $insertedIds
    ) {}
}

class MongoCursor implements \Iterator
{
    private array $data = [];
    private int $position = 0;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function current(): mixed { return $this->data[$this->position] ?? null; }
    public function key(): mixed { return $this->position; }
    public function next(): void { $this->position++; }
    public function rewind(): void { $this->position = 0; }
    public function valid(): bool { return isset($this->data[$this->position]); }
}

class MongoCommandResult
{
    public function __construct(public array $data) {}
    
    public function toArray(): array { return [$this->data]; }
}

class UTCDateTime
{
    public function __construct(public int $milliseconds) {}
    
    public function toDateTime(): \DateTime
    {
        return (new \DateTime())->setTimestamp($this->milliseconds / 1000);
    }
}

class ObjectId
{
    public function __construct(public string $id = '') {}
    
    public function __toString(): string { return $this->id ?: bin2hex(random_bytes(12)); }
}

class BulkWrite
{
    private array $operations = [];

    public function insert(array $document): self
    {
        $this->operations[] = ['insert' => $document];
        return $this;
    }

    public function update(array $filter, array $update, array $options = []): self
    {
        $this->operations[] = ['update' => ['filter' => $filter, 'update' => $update, 'options' => $options]];
        return $this;
    }

    public function delete(array $filter, array $options = []): self
    {
        $this->operations[] = ['delete' => ['filter' => $filter, 'options' => $options]];
        return $this;
    }
}

class Query
{
    public function __construct(public array $filter, public array $options = []) {}
}

class Command
{
    public function __construct(public array $command) {}
}

class WriteConcern
{
    public function __construct(public int $w = 1, public int $wtimeout = 0, public bool $j = false) {}
}

class ReadPreference
{
    public const RP_PRIMARY = 'primary';
    public function __construct(public string $mode = self::RP_PRIMARY) {}
}