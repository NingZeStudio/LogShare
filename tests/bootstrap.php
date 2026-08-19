<?php

declare(strict_types=1);

require_once __DIR__ . '/../core.php';

// Ensure config is loaded for tests
if (!file_exists(__DIR__ . '/../Config.inc.php')) {
    copy(__DIR__ . '/../Config.inc.example.php', __DIR__ . '/../Config.inc.php');
}

// Load mock classes before aliasing (they are outside the src/ autoloader)
require_once __DIR__ . '/Mocks/RedisMock.php';
require_once __DIR__ . '/Mocks/MongoDBMock.php';

// Mock Redis/Mongo for unit tests
if (!extension_loaded('redis')) {
    class_alias('Tests\Mocks\RedisMock', 'Redis');
}

if (!extension_loaded('mongodb')) {
    class_alias('Tests\Mocks\MongoDBMock', 'MongoDB\Driver\Manager');
    class_alias('Tests\Mocks\MongoDBMock', 'MongoDB\Driver\BulkWrite');
    class_alias('Tests\Mocks\MongoDBMock', 'MongoDB\Driver\Query');
    class_alias('Tests\Mocks\MongoDBMock', 'MongoDB\Driver\Command');
    class_alias('Tests\Mocks\MongoDBMock', 'MongoDB\Driver\Cursor');
    class_alias('Tests\Mocks\MongoDBMock', 'MongoDB\Driver\WriteConcern');
    class_alias('Tests\Mocks\MongoDBMock', 'MongoDB\Driver\ReadPreference');
    class_alias('Tests\Mocks\MongoDBMock', 'MongoDB\BSON\UTCDateTime');
    class_alias('Tests\Mocks\MongoDBMock', 'MongoDB\BSON\ObjectId');
}

// Helper functions for tests
function createTestLog(string $content = ''): \App\Log {
    $log = new \App\Log();
    $log->setData($content ?: <<<'LOG'
[12:34:56] [Server thread/INFO]: Starting minecraft server version 1.20.1
[12:34:56] [Server thread/INFO]: Loading properties
[12:34:56] [Server thread/INFO]: Default game type: SURVIVAL
[12:34:57] [Server thread/INFO]: Generating keypair
[12:34:57] [Server thread/INFO]: Starting Minecraft server on *:25565
LOG
    );
    return $log;
}

function createTestToken(): \App\Data\Token {
    return new \App\Data\Token();
}

function assertFilterRedacts(string $filterClass, string $input, string $expectedNotContains): void {
    $output = $filterClass::filter($input);
    expect($output)->not->toContain($expectedNotContains);
}