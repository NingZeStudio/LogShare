<?php

declare(strict_types=1);

namespace App\Rag;

use App\Config;
use Hyperf\Coroutine\Coroutine;

class RagManager
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $memoryStatus = null;

    private static function getStatusFilePath(): string
    {
        $dir = (defined('CORE_PATH') ? CORE_PATH : (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2))) . '/runtime';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/rag_build_status.json';
    }

    /**
     * Read current build status.
     *
     * @return array{status: string, startedAt: ?int, finishedAt: ?int, elapsedSeconds: ?int, result: ?array<string, mixed>, error: ?string}
     */
    public static function getBuildStatus(): array
    {
        if (self::$memoryStatus !== null && ($selfStatus = self::$memoryStatus['status'] ?? '') === 'building') {
            /** @var array{status: string, startedAt: ?int, finishedAt: ?int, elapsedSeconds: ?int, result: ?array<string, mixed>, error: ?string} */
            return self::$memoryStatus;
        }

        $file = self::getStatusFilePath();
        if (is_file($file)) {
            $content = @file_get_contents($file);
            if ($content !== false && $content !== '') {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    /** @var array{status: string, startedAt: ?int, finishedAt: ?int, elapsedSeconds: ?int, result: ?array<string, mixed>, error: ?string} $data */
                    return $data;
                }
            }
        }

        return [
            'status' => 'idle',
            'startedAt' => null,
            'finishedAt' => null,
            'elapsedSeconds' => null,
            'result' => null,
            'error' => null,
        ];
    }

    /**
     * Persist build status to memory and disk.
     *
     * @param array<string, mixed> $status
     */
    private static function setBuildStatus(array $status): void
    {
        self::$memoryStatus = $status;
        $file = self::getStatusFilePath();
        $encoded = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            @file_put_contents($file, $encoded);
        }
    }

    /**
     * Return knowledge base metrics and topic distributions.
     *
     * @return array<string, mixed>
     */
    public static function getStats(): array
    {
        $dbPath = RagSearch::resolveDbPath();
        $exists = is_file($dbPath);
        $fileSize = $exists ? (int) @filesize($dbPath) : 0;
        $lastModified = $exists ? (int) @filemtime($dbPath) : null;

        $chunks = 0;
        $embedded = 0;
        $topics = [];

        if ($exists) {
            try {
                $rag = new RagSearch($dbPath);
                $pdo = $rag->getPdo();

                $stmt = $pdo->query('SELECT count(*) AS c FROM docs');
                if ($stmt !== false) {
                    $row = $stmt->fetch();
                    $chunks = (int) ($row['c'] ?? 0);
                }

                try {
                    $embedStmt = $pdo->query('SELECT count(*) AS c FROM doc_embeddings');
                    if ($embedStmt !== false) {
                        $eRow = $embedStmt->fetch();
                        $embedded = (int) ($eRow['c'] ?? 0);
                    }
                } catch (\Throwable) {
                    $embedded = 0;
                }

                $topics = $rag->topics();
            } catch (\Throwable) {
                // If database is corrupted or unreadable, fall back gracefully
            }
        }

        $aiConfig = Config::Get('ai');
        $semanticEnabled = (bool) ($aiConfig['rag']['enabled'] ?? false);
        $providers = $aiConfig['rag']['providers'] ?? [];

        return [
            'dbPath' => $dbPath,
            'exists' => $exists,
            'fileSize' => $fileSize,
            'lastModified' => $lastModified,
            'chunks' => $chunks,
            'embedded' => $embedded,
            'semanticEnabled' => $semanticEnabled,
            'providerCount' => is_array($providers) ? count($providers) : 0,
            'buildStatus' => self::getBuildStatus(),
            'topics' => $topics,
        ];
    }

    /**
     * Trigger asynchronous knowledge base rebuild.
     *
     * @return array{success: bool, status: string, message: string}
     */
    public static function triggerBuild(): array
    {
        $current = self::getBuildStatus();
        if ($current['status'] === 'building') {
            // Guard against stale building status (e.g. process was killed > 300s ago)
            $startedAt = $current['startedAt'];
            if ($startedAt !== null && (time() - $startedAt) < 300) {
                return [
                    'success' => false,
                    'status' => 'building',
                    'message' => '知识库构建任务正在执行中，请勿重复触发',
                ];
            }
        }

        $status = [
            'status' => 'building',
            'startedAt' => time(),
            'finishedAt' => null,
            'elapsedSeconds' => null,
            'result' => null,
            'error' => null,
        ];
        self::setBuildStatus($status);

        if (class_exists(Coroutine::class) && Coroutine::inCoroutine()) {
            Coroutine::create(function () {
                self::runBuild();
            });
        } else {
            // Fallback for non-coroutine runtime or sync execution
            self::runBuild();
        }

        return [
            'success' => true,
            'status' => 'building',
            'message' => '知识库构建已启动',
        ];
    }

    /**
     * Execute build logic synchronously and update status.
     *
     * @return array<string, mixed>
     */
    public static function runBuild(): array
    {
        $startedAt = time();
        $baseDir = defined('CORE_PATH') ? CORE_PATH : (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2));
        $knowledgeDir = $baseDir . '/rag/knowledge';
        $dbPath = RagSearch::resolveDbPath();
        $semantic = RagSearch::semanticClientFromConfig();

        try {
            $rag = new RagSearch($dbPath);
            $result = $rag->buildIndex($knowledgeDir, $semantic);
            $finishedAt = time();

            $status = [
                'status' => 'success',
                'startedAt' => $startedAt,
                'finishedAt' => $finishedAt,
                'elapsedSeconds' => $finishedAt - $startedAt,
                'result' => $result,
                'error' => null,
            ];
            self::setBuildStatus($status);
            return $status;
        } catch (\Throwable $e) {
            $finishedAt = time();
            $status = [
                'status' => 'failed',
                'startedAt' => $startedAt,
                'finishedAt' => $finishedAt,
                'elapsedSeconds' => $finishedAt - $startedAt,
                'result' => null,
                'error' => $e->getMessage(),
            ];
            self::setBuildStatus($status);
            return $status;
        }
    }

    /**
     * Test knowledge search query.
     *
     * @param string $query
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $query, int $limit = 5): array
    {
        $dbPath = RagSearch::resolveDbPath();
        if (!is_file($dbPath)) {
            return [];
        }

        $rag = new RagSearch($dbPath);
        return $rag->search($query, max(1, min(20, $limit)));
    }
}
