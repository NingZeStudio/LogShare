<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Data\Token;
use App\Storage\MariaDbStorage;
use Hyperf\DbConnection\Db;
use Tests\HttpTestCase;

class MariaDbStorageTest extends HttpTestCase
{
    private bool $dbAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            Db::statement('SELECT 1');
            $this->ensureSchema();
            $this->dbAvailable = true;
        } catch (\Throwable $e) {
            $this->dbAvailable = false;
        }
    }

    private function ensureSchema(): void
    {
        Db::statement("CREATE TABLE IF NOT EXISTS logs (
            id CHAR(6) PRIMARY KEY,
            data LONGTEXT NOT NULL,
            token VARCHAR(64) NULL,
            source VARCHAR(64) NULL,
            created INT UNSIGNED NOT NULL,
            KEY idx_created (created)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        Db::statement("CREATE TABLE IF NOT EXISTS log_files (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            log_id CHAR(6) NOT NULL,
            name VARCHAR(512) NOT NULL,
            data LONGTEXT NOT NULL,
            size INT UNSIGNED NOT NULL,
            KEY idx_log_files_log_id (log_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        Db::statement("CREATE TABLE IF NOT EXISTS log_metadata (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            log_id CHAR(6) NOT NULL,
            `key` VARCHAR(64) NOT NULL,
            `value` TEXT NULL,
            `label` VARCHAR(128) NULL,
            `visible` TINYINT(1) NOT NULL DEFAULT 1,
            KEY idx_log_metadata_log_id (log_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function requireDb(): void
    {
        if (!$this->dbAvailable) {
            $this->markTestSkipped('MariaDB unavailable — skipping MariaDbStorage test');
        }
    }

    public function testPutGetRoundTrip(): void
    {
        $this->requireDb();

        $id = MariaDbStorage::Put('main content', new Token(), [], 'test', [
            ['name' => 'extra.log', 'data' => 'extra content'],
        ]);

        expect($id)->toBeInstanceOf(\App\Id::class);

        $result = MariaDbStorage::Get($id);
        expect($result)->not->toBeNull();
        expect($result['data'])->toBe('main content');
        expect($result['source'])->toBe('test');
        expect($result['token'])->toBeString();
        expect($result['files'])->toHaveCount(1);
        expect($result['files'][0]['name'])->toBe('extra.log');
        expect($result['files'][0]['data'])->toBe('extra content');
        expect($result['files'][0]['size'])->toBe(strlen('extra content'));

        $meta = MariaDbStorage::Get($id, false);
        expect($meta['files'][0])->toHaveKeys(['name', 'size']);
        expect($meta['files'][0])->not->toHaveKey('data');

        expect(MariaDbStorage::Delete($id))->toBeTrue();
        expect(MariaDbStorage::Get($id))->toBeNull();
    }

    public function testRenewUpdatesCreated(): void
    {
        $this->requireDb();

        $id = MariaDbStorage::Put('renew me', new Token());
        $before = MariaDbStorage::Get($id);
        expect($before['created'])->toBeInt();

        sleep(1);
        expect(MariaDbStorage::Renew($id))->toBeTrue();
        $after = MariaDbStorage::Get($id);
        expect($after['created'])->toBeGreaterThanOrEqual($before['created']);

        MariaDbStorage::Delete($id);
    }

    public function testCleanupExpiredRemovesOnlyExpired(): void
    {
        $this->requireDb();

        $expired = MariaDbStorage::Put('expired', new Token());
        $fresh = MariaDbStorage::Put('fresh', new Token());
        Db::table('logs')->where('id', $expired->getRaw())->update(['created' => time() - 99999999]);

        $deleted = MariaDbStorage::CleanupExpired();
        expect($deleted)->toBeGreaterThanOrEqual(1);
        expect(MariaDbStorage::Get($expired))->toBeNull();
        expect(MariaDbStorage::Get($fresh))->not->toBeNull();

        MariaDbStorage::Delete($fresh);
    }

    public function testRenewedLogIsNotRemovedAsExpired(): void
    {
        $this->requireDb();

        $id = MariaDbStorage::Put('renewed', new Token());
        Db::table('logs')->where('id', $id->getRaw())->update(['created' => time() - 99999999]);
        expect(MariaDbStorage::Renew($id))->toBeTrue();

        MariaDbStorage::CleanupExpired();
        expect(MariaDbStorage::Get($id))->not->toBeNull();

        MariaDbStorage::Delete($id);
    }

    public function testCleanupEventIsEnabledWhenConfigured(): void
    {
        $this->requireDb();

        # MariaDB 的 information_schema.EVENTS 列名为 INTERVAL_VALUE/INTERVAL_FIELD
        # （MySQL 风格的 EVENT_INTERVAL_* 在 MariaDB 中不存在）
        $event = Db::selectOne("SELECT EVENT_NAME, STATUS, INTERVAL_VALUE, INTERVAL_FIELD FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME = 'cleanup_expired_logs'");
        if ($event === null) {
            $this->markTestSkipped('cleanup_expired_logs event is not installed');
        }

        expect($event->STATUS)->toBe('ENABLED');
        expect($event->INTERVAL_VALUE)->toBe('1');
        expect($event->INTERVAL_FIELD)->toBe('HOUR');
    }

    public function testListAndCountFilters(): void
    {
        $this->requireDb();

        $prefix = 'mdb_test_' . uniqid();
        $id1 = MariaDbStorage::Put('log content 1', new Token(), [], "{$prefix}_client");
        $id2 = MariaDbStorage::Put('log content 2', new Token(), [], "{$prefix}_server");
        $id3 = MariaDbStorage::Put('log content 3', new Token(), [], '未指定');

        try {
            $totalCount = MariaDbStorage::Count();
            expect($totalCount)->toBeGreaterThanOrEqual(3);

            // Filter by source
            $clientCount = MariaDbStorage::Count("{$prefix}_client");
            expect($clientCount)->toBe(1);

            $clientList = MariaDbStorage::List(10, 0, "{$prefix}_client");
            expect($clientList)->toHaveCount(1);
            expect($clientList[0]['id'])->toBe($id1->get());
            expect($clientList[0]['source'])->toBe("{$prefix}_client");

            // Filter by '未指定'
            $unspecList = MariaDbStorage::List(10, 0, '未指定');
            expect(count($unspecList))->toBeGreaterThanOrEqual(1);

            // Filter by keyword (ID matching)
            $kwList = MariaDbStorage::List(10, 0, null, null, null, $id2->get());
            expect($kwList)->toHaveCount(1);
            expect($kwList[0]['id'])->toBe($id2->get());

            // Filter by time range
            $now = time();
            $timeCount = MariaDbStorage::Count(null, $now - 60, $now + 60);
            expect($timeCount)->toBeGreaterThanOrEqual(3);
        } finally {
            MariaDbStorage::Delete($id1);
            MariaDbStorage::Delete($id2);
            MariaDbStorage::Delete($id3);
        }
    }

    public function testMetadataSerializationAndRoundTrip(): void
    {
        $this->requireDb();

        $entries = [
            \App\Data\MetadataEntry::fromArray(['key' => 'version', 'value' => '1.20.1', 'label' => 'MC Version', 'visible' => true]),
            \App\Data\MetadataEntry::fromArray(['key' => 'mods', 'value' => ['fabric', 'sodium'], 'label' => 'Installed Mods', 'visible' => true]),
            \App\Data\MetadataEntry::fromArray(['key' => 'active', 'value' => true, 'label' => 'Active Status', 'visible' => false]),
        ];

        $id = MariaDbStorage::Put('log with metadata', new Token(), $entries);
        try {
            $result = MariaDbStorage::Get($id);
            expect($result)->not->toBeNull();
            expect($result['metadata'])->toBeArray();
            expect(count($result['metadata']))->toBe(3);

            $metaByKey = [];
            foreach ($result['metadata'] as $meta) {
                $metaByKey[$meta['key']] = $meta;
            }

            expect($metaByKey['version']['value'])->toBe('1.20.1');
            expect($metaByKey['version']['label'])->toBe('MC Version');
            expect($metaByKey['version']['visible'])->toBeTrue();

            expect($metaByKey['mods']['value'])->toBe(['fabric', 'sodium']);
            expect($metaByKey['active']['value'])->toBeTrue();
            expect($metaByKey['active']['visible'])->toBeFalse();
        } finally {
            MariaDbStorage::Delete($id);
        }
    }
}

