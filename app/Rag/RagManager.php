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
     * Diff the knowledge directory against the index by mtime.
     *
     * 索引缺少 chunk_meta mtime（旧索引）时全部文件视为 stale——增量入口
     * 自动退化为全量重建，安全侧。
     *
     * @return array{changed: string[], missing: string[], unchanged: int}
     */
    public static function getStaleFiles(): array
    {
        $dbPath = RagSearch::resolveDbPath();
        if (!is_file($dbPath)) {
            throw new \RuntimeException('Index not built yet; run a full build first');
        }

        $rag = new RagSearch($dbPath);
        $indexed = $rag->indexedMtimes();

        $kbDir = self::getKnowledgeDir();
        $onDisk = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($kbDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || !in_array(strtolower($fileInfo->getExtension()), RagManager::ALLOWED_EXTENSIONS, true)) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($fileInfo->getPathname(), strlen(rtrim($kbDir, '/\\')))), '/');
            $onDisk[$relative] = (int) $fileInfo->getMTime();
        }

        $changed = [];
        $unchanged = 0;
        foreach ($onDisk as $source => $mtime) {
            if (($indexed[$source] ?? -1) !== $mtime) {
                $changed[] = $source;
            } else {
                $unchanged++;
            }
        }
        $missing = array_values(array_diff(array_keys($indexed), array_keys($onDisk)));

        return ['changed' => $changed, 'missing' => $missing, 'unchanged' => $unchanged];
    }

    /**
     * 增量构建：只重索引 mtime 变化的文件、清理已删除文件的 chunk。
     *
     * @return array{updated: int, removed: int, unchanged: int, chunks: int, embedded: int}
     */
    public static function runIncrementalBuild(): array
    {
        $startedAt = time();
        self::setBuildStatus([
            'status' => 'building',
            'startedAt' => $startedAt,
            'finishedAt' => null,
            'elapsedSeconds' => null,
            'result' => null,
            'error' => null,
        ]);

        try {
            $stale = self::getStaleFiles();
            $dbPath = RagSearch::resolveDbPath();
            $rag = new RagSearch($dbPath);
            $semantic = RagSearch::semanticClientFromConfig();
            $kbDir = self::getKnowledgeDir();

            $chunks = 0;
            $embedded = 0;
            foreach ($stale['changed'] as $source) {
                $abs = $kbDir . '/' . $source;
                if (!is_file($abs)) {
                    // diff 与执行之间文件被删：按 missing 处理
                    $rag->forgetSource($source);
                    continue;
                }
                $r = $rag->reindexSource($source, (string) @file_get_contents($abs), $semantic, (int) @filemtime($abs));
                $chunks += $r['chunks'];
                $embedded += $r['embedded'];
            }
            foreach ($stale['missing'] as $source) {
                $rag->forgetSource($source);
            }

            $result = [
                'updated' => count($stale['changed']),
                'removed' => count($stale['missing']),
                'unchanged' => $stale['unchanged'],
                'chunks' => $chunks,
                'embedded' => $embedded,
            ];
            $finishedAt = time();
            self::setBuildStatus([
                'status' => 'success',
                'startedAt' => $startedAt,
                'finishedAt' => $finishedAt,
                'elapsedSeconds' => $finishedAt - $startedAt,
                'result' => $result,
                'error' => null,
            ]);
            return $result;
        } catch (\Throwable $e) {
            $finishedAt = time();
            self::setBuildStatus([
                'status' => 'failed',
                'startedAt' => $startedAt,
                'finishedAt' => $finishedAt,
                'elapsedSeconds' => $finishedAt - $startedAt,
                'result' => null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 异步触发增量构建（复用全量构建的状态文件与 building 防重入锁）。
     *
     * @return array{success: bool, status: string, message: string}
     */
    public static function triggerIncrementalBuild(): array
    {
        $current = self::getBuildStatus();
        $startedAt = $current['startedAt'];
        if ($current['status'] === 'building' && $startedAt !== null && (time() - (int) $startedAt) < 300) {
            return ['success' => false, 'status' => 'building', 'message' => '知识库构建任务正在执行中，请勿重复触发'];
        }

        if (class_exists(Coroutine::class) && Coroutine::inCoroutine()) {
            Coroutine::create(function () {
                try {
                    self::runIncrementalBuild();
                } catch (\Throwable) {
                    // 状态已落盘 failed，无需在此抛出协程异常
                }
            });
            return ['success' => true, 'status' => 'building', 'message' => '增量构建已启动'];
        }

        // CLI/测试环境同步执行
        self::runIncrementalBuild();
        return ['success' => true, 'status' => 'success', 'message' => '增量构建已完成'];
    }

    /**
     * 文档保存/删除后的单文件热更新（受 ai.rag.incrementalBuild 开关约束）。
     *
     * 失败只记日志不抛出：磁盘写入已成功，索引落后可以由下一次构建挽回。
     */
    private static function hotUpdateSource(string $relativePath, bool $exists): void
    {
        if (!RagSearch::incrementalBuildEnabled()) {
            return;
        }
        try {
            $dbPath = RagSearch::resolveDbPath();
            if (!is_file($dbPath)) {
                return; // 索引未建，热更新无从谈起
            }
            $rag = new RagSearch($dbPath);
            if ($exists) {
                $abs = self::getKnowledgeDir() . '/' . $relativePath;
                if (is_file($abs)) {
                    $rag->reindexSource($relativePath, (string) file_get_contents($abs), RagSearch::semanticClientFromConfig(), (int) filemtime($abs));
                }
            } else {
                $rag->forgetSource($relativePath);
            }
        } catch (\Throwable $e) {
            \App\Syslog::error('RAG', "hot update failed for {$relativePath}: " . $e->getMessage());
        }
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
     * ai.rag.incrementalBuild 开启且索引已存在时自动降级为增量构建
     * （rag/knowledge 全量重扫成本高，Admin 触发按钮无需区分入口）；
     * 索引缺失时增量无从比对，安全侧回退全量。
     *
     * @return array<string, mixed>
     */
    public static function runBuild(): array
    {
        if (RagSearch::incrementalBuildEnabled() && is_file(RagSearch::resolveDbPath())) {
            try {
                self::runIncrementalBuild();
            } catch (\Throwable) {
                // 状态已由 runIncrementalBuild 落盘 failed，保持 runBuild 不抛出的契约
            }
            return self::getBuildStatus();
        }

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

    public const ALLOWED_EXTENSIONS = ['md', 'txt', 'log'];
    public const MAX_UPLOAD_SIZE = 5 * 1024 * 1024; // 5MB

    /**
     * Get knowledge base directory.
     */
    public static function getKnowledgeDir(): string
    {
        $baseDir = defined('CORE_PATH') ? CORE_PATH : (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2));
        return $baseDir . '/rag/knowledge';
    }

    /**
     * Resolve and validate a relative document path strictly against directory traversal.
     *
     * @param string $relativePath
     * @param bool $mustExist
     * @return string Absolute file path
     * @throws \InvalidArgumentException
     */
    public static function resolveSafePath(string $relativePath, bool $mustExist = true): string
    {
        $kbDir = realpath(self::getKnowledgeDir());
        if ($kbDir === false || !is_dir($kbDir)) {
            throw new \RuntimeException('Knowledge base directory not found');
        }

        $trimmed = trim(str_replace('\\', '/', $relativePath));
        if ($trimmed === '' || str_contains($trimmed, "\0") || str_contains($trimmed, '..')) {
            throw new \InvalidArgumentException('Invalid path: contains null bytes or directory traversal sequences');
        }

        $trimmed = ltrim($trimmed, '/');
        $segments = explode('/', $trimmed);
        if (count($segments) < 2) {
            throw new \InvalidArgumentException('Invalid path: must specify a topic directory and a filename');
        }

        $topic = $segments[0];
        $registeredTopics = RagSearch::getTopicDescriptions();
        if (!isset($registeredTopics[$topic])) {
            throw new \InvalidArgumentException("Invalid topic directory '{$topic}': not registered in TOPIC_DESCRIPTIONS");
        }

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Invalid path segment');
            }
            if (!preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_\-\.]+$/u', $segment)) {
                throw new \InvalidArgumentException("Invalid character in path segment '{$segment}'");
            }
        }

        $filename = end($segments);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException("Disallowed file extension '.{$ext}'. Allowed: " . implode(', ', self::ALLOWED_EXTENSIONS));
        }

        $targetPath = $kbDir . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);

        if ($mustExist) {
            if (!is_file($targetPath)) {
                throw new \InvalidArgumentException("Document not found: {$trimmed}");
            }
            $realTarget = realpath($targetPath);
            if ($realTarget === false || !str_starts_with($realTarget, $kbDir . DIRECTORY_SEPARATOR)) {
                throw new \InvalidArgumentException('Path traversal detected');
            }
            return $realTarget;
        }

        $parentDir = dirname($targetPath);
        if (is_dir($parentDir)) {
            $realParent = realpath($parentDir);
            if ($realParent === false || (!str_starts_with($realParent, $kbDir . DIRECTORY_SEPARATOR) && $realParent !== $kbDir)) {
                throw new \InvalidArgumentException('Path traversal detected on parent directory');
            }
        }

        return $targetPath;
    }

    /**
     * List knowledge base documents, grouped topics and statistics.
     *
     * @param string|null $topicFilter
     * @param string|null $keywordFilter
     * @return array<string, mixed>
     */
    public static function listDocs(?string $topicFilter = null, ?string $keywordFilter = null): array
    {
        $kbDir = self::getKnowledgeDir();
        if (!is_dir($kbDir)) {
            return ['topics' => [], 'docs' => [], 'total' => 0];
        }

        $topicDescriptions = RagSearch::getTopicDescriptions();
        $topicStats = [];
        foreach ($topicDescriptions as $t => $desc) {
            $topicStats[$t] = [
                'name' => $t,
                'description' => $desc,
                'docCount' => 0,
                'totalBytes' => 0,
            ];
        }

        $docs = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($kbDir, \FilesystemIterator::SKIP_DOTS)
        );

        $kw = $keywordFilter !== null ? mb_strtolower(trim($keywordFilter)) : '';
        $filterTopic = $topicFilter !== null ? trim($topicFilter) : '';

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $ext = strtolower($fileInfo->getExtension());
            if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }

            $pathname = $fileInfo->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($pathname, strlen(rtrim($kbDir, '/\\')))), '/');
            $parts = explode('/', $relative);
            $topic = $parts[0];

            $size = (int) $fileInfo->getSize();
            $mtime = (int) $fileInfo->getMTime();

            if (isset($topicStats[$topic])) {
                $topicStats[$topic]['docCount']++;
                $topicStats[$topic]['totalBytes'] += $size;
            }

            if ($filterTopic !== '' && $topic !== $filterTopic) {
                continue;
            }

            if ($kw !== '') {
                $matchRel = mb_strpos(mb_strtolower($relative), $kw) !== false;
                $matchName = mb_strpos(mb_strtolower($fileInfo->getFilename()), $kw) !== false;
                if (!$matchRel && !$matchName) {
                    continue;
                }
            }

            $docs[] = [
                'path' => $relative,
                'name' => $fileInfo->getFilename(),
                'topic' => $topic,
                'topicDescription' => $topicDescriptions[$topic] ?? '',
                'size' => $size,
                'mtime' => $mtime,
                'extension' => $ext,
            ];
        }

        usort($docs, fn($a, $b) => strcmp($a['path'], $b['path']));

        return [
            'topics' => array_values($topicStats),
            'docs' => $docs,
            'total' => count($docs),
        ];
    }

    /**
     * Read full content and metadata of a knowledge base document.
     *
     * @param string $relativePath
     * @return array<string, mixed>
     */
    public static function readDoc(string $relativePath): array
    {
        $realPath = self::resolveSafePath($relativePath, true);
        $content = @file_get_contents($realPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read document: {$relativePath}");
        }

        $parts = explode('/', str_replace('\\', '/', $relativePath));
        $topic = $parts[0];
        $desc = RagSearch::getTopicDescriptions()[$topic] ?? '';

        return [
            'path' => $relativePath,
            'name' => basename($realPath),
            'topic' => $topic,
            'topicDescription' => $desc,
            'size' => (int) filesize($realPath),
            'mtime' => (int) filemtime($realPath),
            'content' => $content,
        ];
    }

    /**
     * Create or update a knowledge base document.
     *
     * @param string $topic
     * @param string $filename
     * @param string $content
     * @param bool $isNew
     * @return array<string, mixed>
     */
    public static function saveDoc(string $topic, string $filename, string $content, bool $isNew = false): array
    {
        $topic = trim($topic);
        $filename = trim($filename);

        if ($topic === '' || $filename === '') {
            throw new \InvalidArgumentException('Topic and filename are required');
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '') {
            $filename .= '.md';
        }

        $relativePath = $topic . '/' . $filename;
        $targetPath = self::resolveSafePath($relativePath, false);

        if ($isNew && file_exists($targetPath)) {
            throw new \InvalidArgumentException("Document already exists: {$relativePath}");
        }

        $parentDir = dirname($targetPath);
        if (!is_dir($parentDir)) {
            @mkdir($parentDir, 0755, true);
        }

        $tmpFile = $targetPath . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmpFile, $content) === false) {
            @unlink($tmpFile);
            throw new \RuntimeException("Failed to write temporary file: {$tmpFile}");
        }

        if (!rename($tmpFile, $targetPath)) {
            @unlink($tmpFile);
            throw new \RuntimeException("Failed to atomically replace document: {$targetPath}");
        }

        // 增量开关打开时热更新该文件的索引（失败仅记日志，不阻塞保存）
        self::hotUpdateSource($relativePath, true);

        return [
            'path' => $relativePath,
            'name' => $filename,
            'topic' => $topic,
            'size' => (int) filesize($targetPath),
            'mtime' => (int) filemtime($targetPath),
        ];
    }

    /**
     * Delete a knowledge base document.
     *
     * @param string $relativePath
     * @return array<string, mixed>
     */
    public static function deleteDoc(string $relativePath): array
    {
        $normalized = trim(str_replace('\\', '/', $relativePath));
        $realPath = self::resolveSafePath($relativePath, true);
        if (!@unlink($realPath)) {
            throw new \RuntimeException("Failed to delete document: {$relativePath}");
        }

        // 与索引中记录的 source 形式对齐（buildIndex 的相对路径无前导斜杠）
        self::hotUpdateSource(ltrim($normalized, '/'), false);

        return [
            'path' => $relativePath,
            'deleted' => true,
        ];
    }

    /**
     * Save an uploaded document file into the knowledge base.
     *
     * @param string $topic
     * @param string $filename
     * @param string $tmpFilePath
     * @return array<string, mixed>
     */
    public static function uploadDoc(string $topic, string $filename, string $tmpFilePath): array
    {
        if (!is_file($tmpFilePath)) {
            throw new \InvalidArgumentException('Uploaded temporary file not found');
        }

        $size = (int) filesize($tmpFilePath);
        if ($size > self::MAX_UPLOAD_SIZE) {
            throw new \InvalidArgumentException('File exceeds maximum upload size (5MB)');
        }

        $content = @file_get_contents($tmpFilePath);
        if ($content === false) {
            throw new \RuntimeException('Failed to read uploaded file contents');
        }

        return self::saveDoc($topic, $filename, $content, false);
    }
}
