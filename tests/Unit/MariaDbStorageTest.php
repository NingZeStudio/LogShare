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

        $event = Db::selectOne("SELECT EVENT_NAME, STATUS, EVENT_INTERVAL_VALUE, EVENT_INTERVAL_FIELD FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME = 'cleanup_expired_logs'");
        if ($event === null) {
            $this->markTestSkipped('cleanup_expired_logs event is not installed');
        }

        expect($event->STATUS)->toBe('ENABLED');
        expect($event->EVENT_INTERVAL_VALUE)->toBe('1');
        expect($event->EVENT_INTERVAL_FIELD)->toBe('HOUR');
    }
}
