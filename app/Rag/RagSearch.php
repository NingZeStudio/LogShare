<?php

namespace App\Rag;

/**
 * RagSearch: local retrieval over a static knowledge base using SQLite FTS5 (BM25).
 *
 * Zero-network, zero-embedding. English/code tokens go through FTS5 with prefix
 * matching; the raw query is additionally matched with LIKE as a fallback so
 * CJK phrases and partial substrings are still found.
 *
 * Requires: PHP 8.1+, pdo_sqlite.
 */
class RagSearch
{
    /**
     * 知识库主题目录的人工描述，是 list_topics / 系统提示词主题地图的可读性来源。
     *
     * 模型根据这段描述决定检索方向：目录名本身不可读的（如 mg-issues、zl2-issues）
     * 必须写清楚内容与诊断价值。新增知识库目录时必须在此登记，否则主题地图对该
     * 目录没有描述；与 rag/knowledge/ 实际目录的双向一致性由
     * `topic descriptions cover all knowledge directories` 单测守门。
     *
     * 描述文本纪律：只写定性内容，禁止写入会随知识库更新漂移的量化数字；
     * 数量信息由 topics() 返回的动态 count 承载。
     */
    private const TOPIC_DESCRIPTIONS = [
        // ── 核心诊断资产与常见报错分析 ──
        '日志分析' => '成体系的报错条目库（KB 编号条目），按异常类型归类，每条含现象、原因与解决方案',
        'patterns' => '常见崩溃与故障模式库：mixin 注入失败、内存不足、Java 版本错误、mod 依赖缺失等，按「签名-含义-解决方案」组织',
        'format' => '三大日志文件（crash-report / hs_err_pid / latest.log）的格式解读方法与信号速查',
        'android-native-lib' => 'Android 原生库（lib 型 mod）加载问题：动态库缺失与插件系统',
        // ── 手机启动器常识与版本列表 ──
        'mobile_launcher' => '手机启动器常识：渲染器选择与 Minecraft 版本更新列表对应关系',
    ];

    /**
     * @return array<string, string>
     */
    public static function getTopicDescriptions(): array
    {
        return self::TOPIC_DESCRIPTIONS;
    }

    private \PDO $pdo;

    public function __construct(private string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            throw new \RuntimeException("RAG database directory does not exist: {$dir}");
        }

        $this->pdo = new \PDO('sqlite:' . $dbPath);
        $this->configurePdo($this->pdo);
        $this->ensureSchema($this->pdo);
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Resolve the SQLite database path.
     *
     * Priority: RAG_DB_PATH env var (dev/tests override) > ai.mcp.rag.db in
     * Config.inc.php (via the App\Config singleton) > default <project>/rag/index.db.
     *
     * @return string
     */
    public static function resolveDbPath(): string
    {
        $projectRoot = dirname(__DIR__, 2);

        $env = getenv('RAG_DB_PATH');
        if (is_string($env) && $env !== '') {
            return $env;
        }

        $db = \App\Config::Get('ai')['mcp']['rag']['db'] ?? null;
        if (is_string($db) && $db !== '') {
            return str_starts_with($db, '/') ? $db : $projectRoot . '/' . $db;
        }

        return $projectRoot . '/rag/index.db';
    }

    private static function configurePdo(\PDO $pdo): void
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    private function ensureSchema(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE VIRTUAL TABLE IF NOT EXISTS docs USING fts5(
                title,
                body,
                source,
                tokenize = 'porter unicode61'
            )"
        );
        // 语义检索的向量存储：rowid 对应 docs 表 rowid；vec 为 packed float32。
        // 语义增强未开启/嵌入失败时该表为空，检索自动退回纯词法。
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS doc_embeddings(
                rowid INTEGER PRIMARY KEY,
                vec BLOB NOT NULL
            )"
        );
        // chunk 元数据侧表（docs 是 FTS5 虚表不能 ADD COLUMN）：rowid 与 docs
        // 一一对应。token_count 供 HYBRID 审计，parent_rowid 表达父子层级，
        // source_mtime 支撑增量索引（Step 5）的文件变更比对。
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS chunk_meta(
                rowid INTEGER PRIMARY KEY,
                token_count INTEGER NOT NULL DEFAULT 0,
                parent_rowid INTEGER,
                start_offset INTEGER NOT NULL DEFAULT 0,
                end_offset INTEGER NOT NULL DEFAULT 0,
                source_mtime INTEGER NOT NULL DEFAULT 0
            )"
        );
    }

    /**
     * 读取 ai.rag.chunker 配置；任何异常回退默认策略（heading = 旧行为）。
     */
    public static function chunkStrategyFromConfig(): ChunkStrategy
    {
        try {
            return ChunkStrategy::fromConfig(\App\Config::Get('ai')['rag']['chunker'] ?? null);
        } catch (\Throwable) {
            return ChunkStrategy::HEADING_ONLY;
        }
    }

    /**
     * Index every supported file under a directory (recursively).
     *
     * The index is built into a temporary database file, then atomically renamed
     * to the target path on success. A failed build never corrupts the live
     * database — the old index remains intact for online queries.
     *
     * When a configured SemanticClient is supplied, chunk embeddings are
     * generated in batches after the lexical insert; failures leave
     * doc_embeddings empty and search transparently falls back to lexical.
     *
     * @param string $knowledgeDir
     * @param SemanticClient|null $semantic
     * @return array{files: int, chunks: int, embedded: int}
     */
    public function buildIndex(string $knowledgeDir, ?SemanticClient $semantic = null): array
    {
        if (!is_dir($knowledgeDir)) {
            throw new \RuntimeException("Knowledge directory does not exist: {$knowledgeDir}");
        }

        $tmpPath = $this->dbPath . '.tmp.' . bin2hex(random_bytes(8));
        $tmpPdo = new \PDO('sqlite:' . $tmpPath);
        self::configurePdo($tmpPdo);
        self::ensureSchema($tmpPdo);

        $strategy = self::chunkStrategyFromConfig();

        try {
            $tmpPdo->beginTransaction();

            $files = 0;
            $chunks = 0;
            $chunkRowids = [];
            $chunkBodies = [];
            $insert = $tmpPdo->prepare("INSERT INTO docs(title, body, source) VALUES (?, ?, ?)");
            $metaInsert = $tmpPdo->prepare(
                "INSERT INTO chunk_meta(rowid, token_count, parent_rowid, start_offset, end_offset, source_mtime)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($knowledgeDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile() || !in_array(strtolower($fileInfo->getExtension()), ['md', 'txt', 'log'], true)) {
                    continue;
                }

                $content = (string) file_get_contents($fileInfo->getPathname());
                $relative = ltrim(substr($fileInfo->getPathname(), strlen(rtrim($knowledgeDir, '/'))), '/');
                $fileMtime = (int) $fileInfo->getMTime();

                // 分块 → 逐个插入，parentId（文件内 chunk 下标）在插入后
                // 解析为真实 rowid，供 parent-child 检索与增量重建使用
                $fileChunks = Chunker::chunk($relative, $content, $strategy);
                $rowidByIndex = [];
                foreach ($fileChunks as $i => $chunk) {
                    $insert->execute([$chunk->title, $chunk->body, $relative]);
                    $rowid = (int) $tmpPdo->lastInsertId();
                    $rowidByIndex[$i] = $rowid;
                    $metaInsert->execute([
                        $rowid,
                        $chunk->tokenCount,
                        $chunk->parentId === null ? null : ($rowidByIndex[$chunk->parentId] ?? null),
                        $chunk->startOffset,
                        $chunk->endOffset,
                        $fileMtime,
                    ]);
                    $chunkRowids[] = $rowid;
                    $chunkBodies[] = $chunk->title . "\n" . $chunk->body;
                    $chunks++;
                }
                $files++;
            }

            $tmpPdo->commit();
        } catch (\Throwable $e) {
            $tmpPdo->rollBack();
            @unlink($tmpPath);
            throw $e;
        }

        $embedded = 0;
        if ($semantic !== null && $semantic->isConfigured() && $chunks > 0) {
            $embedded = $this->embedChunks($tmpPdo, $chunkRowids, $chunkBodies, $semantic);
        }

        $tmpPdo = null;
        if (!rename($tmpPath, $this->dbPath)) {
            @unlink($tmpPath);
            throw new \RuntimeException("Failed to rename temporary index to {$this->dbPath}");
        }

        $this->pdo = new \PDO('sqlite:' . $this->dbPath);
        self::configurePdo($this->pdo);

        return ['files' => $files, 'chunks' => $chunks, 'embedded' => $embedded];
    }

    /**
     * 批量嵌入 chunk 并写入 doc_embeddings。
     * buildIndex 与增量重建共用；返回成功嵌入条数。
     *
     * batch size 自适应（plan 3.7）：成功 +4（上限 64），失败 ÷2（下限 4）
     * 并逐条重试该批；维度漂移（响应维度相对首批突变）时自动重嵌入该批
     * 一次，仍漂移则逐条降级只收维度一致的向量——维度混杂会污染余弦扫描。
     *
     * @param int[] $rowids
     * @param string[] $bodies
     */
    private function embedChunks(\PDO $pdo, array $rowids, array $bodies, SemanticClient $semantic): int
    {
        $embedded = 0;
        $embedStmt = $pdo->prepare("INSERT OR REPLACE INTO doc_embeddings(rowid, vec) VALUES (?, ?)");

        $storeEmbedding = function (int $rowid, array $vec) use ($embedStmt, &$embedded): void {
            $embedStmt->bindValue(1, $rowid, \PDO::PARAM_INT);
            $embedStmt->bindValue(2, VectorIndex::packVector($vec), \PDO::PARAM_LOB);
            $embedStmt->execute();
            $embedded++;
        };

        $batchSize = 16;
        $expectedDims = null;

        $embedSingle = function (int $rowid, string $text, ?int $expectedDims) use ($semantic, $storeEmbedding): bool {
            $text = trim(mb_strcut($text, 0, 4000));
            if ($text === '') {
                return false;
            }
            try {
                $vec = $semantic->embed([$text])[0] ?? null;
                if ($vec === null) {
                    return false;
                }
                if ($expectedDims !== null && count($vec) !== $expectedDims) {
                    return false; // 维度漂移的单条不入库，避免污染余弦扫描
                }
                $storeEmbedding($rowid, $vec);
                return true;
            } catch (\Throwable) {
                return false;
            }
        };

        $pairs = array_map(null, $rowids, $bodies);
        // 指针式切片：batch size 自适应必须在运行时生效
        for ($pos = 0, $total = count($pairs), $batchNo = 0; $pos < $total; $batchNo++) {
            $i = $batchNo;
            $batch = array_slice($pairs, $pos, $batchSize);
            $pos += count($batch);
            $texts = array_map(fn($p) => trim(mb_strcut((string) $p[1], 0, 4000)), $batch);

            try {
                $vectors = $semantic->embed($texts);

                // 维度探测：漂移时重嵌入该批一次（plan 3.7），仍漂移则逐条降级，
                // 只收维度一致的向量
                if ($expectedDims !== null && $vectors !== [] && count($vectors[0]) !== $expectedDims) {
                    \App\Syslog::warning('RAG', "embedding dims drifted ({$expectedDims} -> " . count($vectors[0]) . "), re-embedding batch #{$i}");
                    $vectors = $semantic->embed($texts);
                    if ($vectors !== [] && count($vectors[0]) !== $expectedDims) {
                        \App\Syslog::error('RAG', "embedding dims still mismatched in batch #{$i}, falling back per chunk");
                        foreach ($batch as [$rowid, $body]) {
                            if (!$embedSingle($rowid, (string) $body, $expectedDims)) {
                                \App\Syslog::error('RAG', "chunk rowid={$rowid} skipped: dims mismatch or unembeddable");
                            }
                        }
                        continue;
                    }
                }
                if ($expectedDims === null && $vectors !== []) {
                    $expectedDims = count($vectors[0]);
                }

                $batchSize = min(64, $batchSize + 4); // 自适应扩张
                foreach ($batch as $j => [$rowid,]) {
                    if (!isset($vectors[$j]) || trim($texts[$j]) === '') {
                        continue;
                    }
                    if (count($vectors[$j]) !== $expectedDims) {
                        continue;
                    }
                    $storeEmbedding($rowid, $vectors[$j]);
                }
            } catch (\Throwable $e) {
                $batchSize = max(4, intdiv($batchSize, 2)); // 自适应收缩
                \App\Syslog::error('RAG', 'embedding batch #' . $i . ' failed (' . $e->getMessage() . '), batch -> ' . $batchSize . ', retrying per chunk');
                foreach ($batch as [$rowid, $body]) {
                    if (!$embedSingle($rowid, (string) $body, $expectedDims)) {
                        \App\Syslog::error('RAG', "chunk rowid={$rowid} skipped: unembeddable");
                    }
                }
            }
        }

        return $embedded;
    }

    /**
     * ai.rag.incrementalBuild 开关（默认 false = 只走全量构建）。
     */
    public static function incrementalBuildEnabled(): bool
    {
        try {
            return (\App\Config::Get('ai')['rag']['incrementalBuild'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 索引内各源文件的 mtime（source → 最近一次索引时的文件 mtime）。
     *
     * @return array<string, int>
     */
    public function indexedMtimes(): array
    {
        $rows = $this->pdo->query(
            'SELECT d.source, max(m.source_mtime) AS mt FROM docs d JOIN chunk_meta m ON m.rowid = d.rowid GROUP BY d.source'
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['source']] = (int) $row['mt'];
        }
        return $out;
    }

    /**
     * 重索引单个源文件：删除旧 chunk（含向量与元数据）后按当前策略重建。
     *
     * 供增量构建与 saveDoc/deleteDoc 热更新共用。父子 chunk 一起删一起建
     * （整文件粒度，不做单 chunk 增量）。语义开启时对新 chunk 重嵌入。
     *
     * $mtime 应传文件的真实 mtime（调用方 filemtime 获取），否则下一次
     * getStaleFiles 比对不中，增量构建会永远把该文件判为 changed。
     *
     * @return array{chunks: int, embedded: int}
     */
    public function reindexSource(string $source, string $content, ?SemanticClient $semantic = null, ?int $mtime = null): array
    {
        $mtime = $mtime ?? time();
        $deleted = $this->pdo->prepare('DELETE FROM docs WHERE source = ?');
        $deleted->bindValue(1, $source);
        $deleted->execute();
        // docs 是虚表没有外键级联：向量与元数据按旧 rowid 手动清理
        $this->pdo->exec('DELETE FROM doc_embeddings WHERE rowid NOT IN (SELECT rowid FROM docs)');
        $this->pdo->exec('DELETE FROM chunk_meta WHERE rowid NOT IN (SELECT rowid FROM docs)');
        // parent_rowid 指向本文件旧父块的孤儿引用一并清除层级（保守置空）
        $this->pdo->exec('UPDATE chunk_meta SET parent_rowid = NULL WHERE parent_rowid NOT IN (SELECT rowid FROM docs)');

        $rowids = [];
        $bodies = [];
        $chunks = 0;
        $insert = $this->pdo->prepare('INSERT INTO docs(title, body, source) VALUES (?, ?, ?)');
        $metaInsert = $this->pdo->prepare(
            'INSERT INTO chunk_meta(rowid, token_count, parent_rowid, start_offset, end_offset, source_mtime)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $strategy = self::chunkStrategyFromConfig();

        $this->pdo->beginTransaction();
        try {
            $fileChunks = Chunker::chunk($source, $content, $strategy);
            $rowidByIndex = [];
            foreach ($fileChunks as $i => $chunk) {
                $insert->execute([$chunk->title, $chunk->body, $source]);
                $rowid = (int) $this->pdo->lastInsertId();
                $rowidByIndex[$i] = $rowid;
                $metaInsert->execute([
                    $rowid,
                    $chunk->tokenCount,
                    $chunk->parentId === null ? null : ($rowidByIndex[$chunk->parentId] ?? null),
                    $chunk->startOffset,
                    $chunk->endOffset,
                    $mtime,
                ]);
                $rowids[] = $rowid;
                $bodies[] = $chunk->title . "\n" . $chunk->body;
                $chunks++;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $embedded = 0;
        if ($semantic !== null && $semantic->isConfigured() && $rowids !== []) {
            $embedded = $this->embedChunks($this->pdo, $rowids, $bodies, $semantic);
        }

        return ['chunks' => $chunks, 'embedded' => $embedded];
    }

    /**
     * 从索引中彻底移除某源文件（文件已删除时调用）。
     */
    public function forgetSource(string $source): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM docs WHERE source = ?');
        $stmt->bindValue(1, $source);
        $stmt->execute();
        $n = $stmt->rowCount();
        $this->pdo->exec('DELETE FROM doc_embeddings WHERE rowid NOT IN (SELECT rowid FROM docs)');
        $this->pdo->exec('DELETE FROM chunk_meta WHERE rowid NOT IN (SELECT rowid FROM docs)');
        $this->pdo->exec('UPDATE chunk_meta SET parent_rowid = NULL WHERE parent_rowid NOT IN (SELECT rowid FROM docs)');
        return $n;
    }

    /**
     * Split a markdown file into chunks on `## ` headings.
     *
     * 委托 Chunker::byHeading（HEADING_ONLY 策略，即当前默认行为），返回
     * 旧版数组形态保持兼容；新代码请直接使用 Chunker::chunk()。
     *
     * @param string $source
     * @param string $content
     * @return array<int, array{title: string, body: string}>
     */
    public static function chunkMarkdown(string $source, string $content): array
    {
        return array_map(
            static fn(Chunk $chunk): array => $chunk->toLegacyArray(),
            Chunker::byHeading($source, $content)
        );
    }

    /**
     * Normalize a topic argument shared by the MCP layer and search().
     *
     * trim → 去首尾 '/' → 空字符串归 null；拒绝路径遍历（'..'）与超长值。
     * RagController::tools/call 用它做入参校验，search() 内部再调用一次做
     * 防御（幂等），两处不再各自维护归一化逻辑，避免演化不一致。
     *
     * @throws \InvalidArgumentException when the value is malformed
     */
    public static function normalizeTopic(mixed $topic): ?string
    {
        if ($topic === null) {
            return null;
        }
        if (!is_string($topic) && !is_numeric($topic)) {
            throw new \InvalidArgumentException('rag_search topic must be a string');
        }

        $normalized = trim((string) $topic);
        $normalized = trim($normalized, '/');
        if ($normalized === '') {
            return null;
        }
        if (strlen($normalized) > 64) {
            throw new \InvalidArgumentException('rag_search topic is too long');
        }
        if (str_contains($normalized, '..')) {
            throw new \InvalidArgumentException('rag_search topic must not contain ".."');
        }
        return $normalized;
    }

    /**
     * 由结果 source 路径反推所属主题目录，分组规则与 topics() 一致（首段为目录，
     * 无目录归入根目录）。供指标统计等调用方复用，避免两处口径漂移。
     */
    public static function topicOfSource(string $source): string
    {
        $parts = explode('/', trim($source, '/'));
        return count($parts) > 1 ? $parts[0] : '(根目录)';
    }

    /**
     * Search the knowledge base.
     *
     * @param string $query
     * @param int $k Maximum number of results
     * @param string|null $topic Optional directory prefix (normalized via normalizeTopic);
     *                           restricts all retrieval paths to sources under "<topic>/"
     * @return array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}>
     */
    public function search(string $query, int $k = 5, ?string $topic = null): array
    {
        $t0 = microtime(true);
        $k = max(1, min((int) $k, 20));
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        // 防御性二次归一化（幂等）：控制器已校验过，直接内部调用同样生效
        $topic = self::normalizeTopic($topic);
        $originalQuery = $query;

        // 阶段指标（Step 7 检索埋点）：随检索流程逐步填充，出口统一上报
        $m = ['query' => mb_substr($query, 0, 120), 'k' => $k, 'topic' => $topic];

        // 查询预处理（ai.rag.queryRewrite.enabled，默认关闭 → 逐字节不变）：
        // 改写只喂给检索通道，embed/缓存仍用原查询；分类偏置只在无显式
        // topic 时生效，模型圈定的目录优先级高于规则猜测。
        $pre = ['query' => $query, 'weights' => [], 'rewritten' => false];
        if (QueryPreProcessor::enabled()) {
            $tPre = microtime(true);
            $pre = QueryPreProcessor::preprocess($query);
            $m['rewrite_ms'] = round((microtime(true) - $tPre) * 1000, 2);
            $m['rewritten'] = $pre['rewritten'];
        }

        // 候选池：语义精排前多召回一些；纯词法路径仍只输出 k 条。
        // 启用精排时按精排器实际容量（ai.rag.rerank.maxCandidates）扩量，
        // 否则配置的 30 永远只喂到 20；未启用返回 null，池尺寸逐字节不变。
        $pool = RetrievalPipeline::poolSize($k, self::rerankMaxCandidates());

        $tLex = microtime(true);
        $results = (new LexicalIndex($this->pdo))->search($pre['query'], self::splitTerms($pre['query']), $pool, $topic);
        $m['lexical_ms'] = round((microtime(true) - $tLex) * 1000, 2);
        $m['lexical_hits'] = count($results);

        // Semantic enhancement: vector recall is primary, lexical results supplement it.
        // 语义召回与结果缓存锚定原始查询（改写词只服务词法通道）。分类偏置生效时
        // 扩大语义截断量到 pool、偏置后再截 k；默认路径仍按 k 截断，缓存键不变。
        $biasable = $topic === null && $pre['weights'] !== [];
        $tSem = microtime(true);
        $final = $this->applySemanticEnhancement($originalQuery, $results, $biasable ? $pool : $k, $pool, $topic, $m);
        $m['semantic_ms'] = round((microtime(true) - $tSem) * 1000, 2);

        if ($biasable) {
            $final = QueryPreProcessor::applyTopicBias($final, $pre['weights']);
        }

        $out = array_slice($final, 0, $k);
        $m['final'] = count($out);
        $m['total_ms'] = round((microtime(true) - $t0) * 1000, 2);
        RetrievalMetrics::record($m);
        return $out;
    }

    /**
     * Process-level cache for semantic enhancement results (query+k → final list).
     *
     * Agent loops re-query with tweaked keywords and users retry the same
     * question; a short TTL avoids paying the embed+rerank double round trip
     * (200-500ms plus tokens) for identical calls. FIFO-capped.
     *
     * @var array<string, array{expires: int, results: array}>
     */
    private static array $semanticCache = [];
    /** @var array<string, int> 每条缓存条目的序列化字节数（增量维护） */
    private static array $semanticCacheSizes = [];
    private static int $semanticCacheBytes = 0;
    private const SEMANTIC_CACHE_TTL = 60;
    private const SEMANTIC_CACHE_MAX = 64;
    private const SEMANTIC_CACHE_MAX_BYTES = 1048576;

    private function applySemanticEnhancement(string $query, array $lexical, int $k, int $pool, ?string $topic = null, array &$metrics = []): array
    {
        $client = self::semanticClientFromConfig();
        if ($client === null || !$client->isConfigured()) {
            return array_slice($lexical, 0, $k);
        }

        // 缓存键必须含 topic：不同目录同名 query 的结果不可互串
        // v3：融合排序去偏（RRF_K/权重/同源配额）后顺序变化，避免常驻 Worker 部署后
        // TTL 内混用旧顺序的结果
        $cacheKey = 'semantic-v3:' . md5($query) . ':' . $k . ':' . ($topic ?? '');
        $cached = self::$semanticCache[$cacheKey] ?? null;
        if ($cached !== null && $cached['expires'] > time()) {
            $metrics['result_cache'] = 1;
            return $cached['results'];
        }

        try {
            $results = $this->runSemanticPipeline($query, $lexical, $k, $pool, $client, $topic, $metrics);
        } catch (\Throwable $e) {
            $metrics['semantic_error'] = $e->getMessage();
            \App\Syslog::error('RAG', 'semantic enhancement failed, falling back to lexical: ' . $e->getMessage());
            return array_slice($lexical, 0, $k);
        }

        $entry = ['expires' => time() + self::SEMANTIC_CACHE_TTL, 'results' => $results];
        $entryBytes = strlen(serialize($entry));
        if ($entryBytes > self::SEMANTIC_CACHE_MAX_BYTES) {
            return $results;
        }
        // 每条 entry 记录自身字节数并累加，淘汰时 O(1) 扣减；
        // 旧实现对整个缓存反复 serialize 计算字节数，为 O(n²)
        while (self::$semanticCache !== []
            && (count(self::$semanticCache) >= self::SEMANTIC_CACHE_MAX
                || self::$semanticCacheBytes + $entryBytes > self::SEMANTIC_CACHE_MAX_BYTES)) {
            $evicted = array_key_first(self::$semanticCache);
            self::$semanticCacheBytes -= self::$semanticCacheSizes[$evicted] ?? 0;
            unset(self::$semanticCache[$evicted], self::$semanticCacheSizes[$evicted]);
        }
        self::$semanticCache[$cacheKey] = $entry;
        self::$semanticCacheSizes[$cacheKey] = $entryBytes;
        self::$semanticCacheBytes += $entryBytes;

        return $results;
    }

    /**
     * Vector-recall candidates are primary and lexical results supplement them. Any failure logs and returns the
     * lexical-only slice — semantic search must never break retrieval.
     *
     * @param int $pool 融合候选池下限（见 search()：启用精排时按精排器容量扩量）
     * @param array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}> $lexical
     * @return array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}>
     */
    private function runSemanticPipeline(string $query, array $lexical, int $k, int $pool, SemanticClient $client, ?string $topic = null, array &$metrics = []): array
    {
        try {
            // 查询向量缓存（Redis + 进程内，ai.rag.semanticCache）：命中则零
            // embedding API 调用。键含模型指纹，切换模型后自然失效。
            $cache = new SemanticCache($client->describe());
            $queryVec = $cache->get($query);
            if ($queryVec === null) {
                $queryVec = $client->embed([$query])[0] ?? null;
                if ($queryVec === null) {
                    throw new \RuntimeException('empty query embedding');
                }
                $cache->set($query, $queryVec);
                RetrievalMetrics::counter('embed_api_calls');
            } else {
                RetrievalMetrics::counter('embed_cache_hits');
            }

            // 向量召回：与全库嵌入算余弦，补足词法漏掉的同义表述；
            // topic 模式下过滤下推到召回 SQL（源头限定目录，无需扩量放大）
            $vectorHits = (new VectorIndex($this->pdo))->topByCosine($queryVec, max($pool, $k * 4), $topic);
            $metrics['vector_hits'] = count($vectorHits);

            // rerank 开关开启：走 RetrievalPipeline（RRF 融合 + LLM 精排）。
            // 关闭时保持既有「向量优先、词法补充」的截断合并，逐字节不变。
            if (self::rerankEnabled()) {
                $pipeline = new RetrievalPipeline(self::rerankerFromConfig());
                $r = $pipeline->retrieve($query, $lexical, $vectorHits, $k);
                $metrics['reranked'] = !empty($r['reranked']);
                $metrics['fused'] = (int) $r['fused'];
                return $r['results'];
            }

            $seen = [];
            $out = [];
            foreach (array_merge($vectorHits, $lexical) as $result) {
                $key = $result['source'] . '#' . $result['title'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $result;
                if (count($out) >= $k) {
                    break;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            \App\Syslog::error('RAG', 'semantic pipeline failed, falling back to lexical: ' . $e->getMessage());
            return array_slice($lexical, 0, $k);
        }
    }

    /** ai.rag.rerank.enabled —— RRF + LLM 精排总开关，默认关闭（保持旧排序）。 */
    public static function rerankEnabled(): bool
    {
        try {
            return (\App\Config::Get('ai')['rag']['rerank']['enabled'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 精排器一次能处理的候选条数上限；未启用精排时返回 null。
     *
     * 融合候选池必须据此扩量（见 RetrievalPipeline::poolSize），否则配置的 30
     * 永远只喂到历史默认的 20；禁用态不读该字段，保证「默认关闭即行为不变」。
     */
    public static function rerankMaxCandidates(): ?int
    {
        return self::rerankEnabled() ? self::configuredMaxCandidates() : null;
    }

    /** ai.rag.rerank.maxCandidates 缺省值。 */
    private const DEFAULT_RERANK_MAX_CANDIDATES = 30;

    /**
     * 读取并校验 ai.rag.rerank.maxCandidates：合法区间 [2, 50]，越界退回默认并告警。
     *
     * 旧的 `max(2, min(50, $v))` 会把 0/1 这类明显笔误静默夹成 2，精排池莫名缩到
     * 历史默认之下；视为配置错误按默认处理更重要。
     */
    private static function configuredMaxCandidates(): int
    {
        try {
            $rerank = (array) (\App\Config::Get('ai')['rag']['rerank'] ?? []);
        } catch (\Throwable) {
            return self::DEFAULT_RERANK_MAX_CANDIDATES;
        }
        $raw = $rerank['maxCandidates'] ?? self::DEFAULT_RERANK_MAX_CANDIDATES;
        $max = is_numeric($raw) ? (int) $raw : -1;
        if ($max < 2 || $max > 50) {
            \App\Syslog::error('RAG', 'invalid ai.rag.rerank.maxCandidates='
                . (is_scalar($raw) ? (string) $raw : gettype($raw))
                . ', falling back to ' . self::DEFAULT_RERANK_MAX_CANDIDATES);
            return self::DEFAULT_RERANK_MAX_CANDIDATES;
        }
        return $max;
    }

    /**
     * 构造精排器：按 ai.rag.rerank.type 分派 http（专用 cross-encoder 端点）
     * 或 llm（复用主分析模型做 listwise 重排）；无 AI 密钥时返回 Noop（仅 RRF）。
     * type=http 但端点配置非法或缺项时退回 llm，不让检索失败。
     */
    private static function rerankerFromConfig(): Rerank\RerankInterface
    {
        $ai = [];
        try {
            $ai = (array) \App\Config::Get('ai');
        } catch (\Throwable) {
            $ai = [];
        }
        $rerank = (array) ($ai['rag']['rerank'] ?? []);
        $max = self::configuredMaxCandidates();
        $type = strtolower(trim((string) ($rerank['type'] ?? 'llm')));

        if ($type === 'http') {
            try {
                $baseUrl = Rerank\HttpReranker::normalizeEndpoint(
                    (string) ($rerank['baseUrl'] ?? ''),
                    ($rerank['allowLoopback'] ?? false) === true
                );
                $model = trim((string) ($rerank['model'] ?? ''));
                if ($baseUrl !== '' && $model !== '') {
                    return new Rerank\HttpReranker(
                        $baseUrl,
                        trim((string) ($rerank['apiKey'] ?? '')),
                        $model,
                        $max,
                        max(1, min(60, (int) ($rerank['timeout'] ?? 10)))
                    );
                }
                \App\Syslog::error('RAG', 'rerank type=http but baseUrl/model unset, falling back to LLM rerank');
            } catch (\Throwable $e) {
                \App\Syslog::error('RAG', 'invalid rerank endpoint config, falling back to LLM rerank: ' . $e->getMessage());
            }
        }

        $hasKeys = !empty($ai['apiKeys']) || !empty($ai['apiKey']);
        if (!$hasKeys) {
            \App\Syslog::error('RAG', 'rerank enabled but no AI credentials configured, falling back to RRF order only');
            return new Rerank\NoopReranker();
        }
        return new Rerank\LLMReranker($max);
    }

    /**
     * Build a SemanticClient from the ai.rag config section; null when disabled.
     * Public: RagBuildCommand uses it to decide whether to embed at build time.
     *
     * Supports the `providers` list (ordered failover) and, for backwards
     * compatibility, a flat top-level baseUrl/apiKey pair.
     */
    public static function semanticClientFromConfig(): ?SemanticClient
    {
        $cfg = \App\Config::Get('ai')['rag'] ?? [];
        if (($cfg['enabled'] ?? false) !== true) {
            return null;
        }

        $providers = [];
        foreach ((array) ($cfg['providers'] ?? []) as $p) {
            if (!is_array($p) || ($p['baseUrl'] ?? '') === '') {
                continue;
            }
            $model = (string) ($p['embeddingModel'] ?? ($cfg['embeddingModel'] ?? 'bge-m3'));
            if (($p['type'] ?? '') === 'ollama') {
                // 本地 Ollama（type=ollama）：loopback 免鉴权，/api/embeddings 协议
                $providers[] = SemanticClient::ollama(
                    (string) ($p['name'] ?? $p['baseUrl']),
                    (string) $p['baseUrl'],
                    $model,
                );
                continue;
            }
            $providers[] = SemanticClient::provider(
                (string) ($p['name'] ?? $p['baseUrl']),
                (string) $p['baseUrl'],
                (string) ($p['apiKey'] ?? ''),
                $model,
            );
        }

        // legacy flat config → single provider
        if ($providers === [] && ($cfg['baseUrl'] ?? '') !== '') {
            $providers[] = SemanticClient::provider(
                'default',
                (string) $cfg['baseUrl'],
                (string) ($cfg['apiKey'] ?? ''),
                (string) ($cfg['embeddingModel'] ?? 'bge-m3'),
            );
        }

        return new SemanticClient($providers, (int) ($cfg['timeout'] ?? 30));
    }

    /**
     * Split a query into distinct non-empty terms on whitespace and punctuation.
     *
     * 委托 LexicalIndex::splitTerms；保留本静态方法是因为既有测试与调用方
     * 通过反射/直调使用它（语义不变）。
     *
     * @param string $query
     * @return array<int, string>
     */
    private static function splitTerms(string $query): array
    {
        return LexicalIndex::splitTerms($query);
    }

    /**
     * Index statistics.
     *
     * @return array{chunks: int}
     */
    public function stats(): array
    {
        $count = $this->pdo->query("SELECT count(*) AS c FROM docs")->fetch()['c'] ?? 0;
        return ['chunks' => (int) $count];
    }

    /**
     * Knowledge base topic overview grouped by source directory.
     *
     * Helps the AI pick relevant search directions before querying.
     *
     * @return array<int, array{dir: string, description: string, count: int, files: array<int, string>}>
     */
    public function topics(): array
    {
        $rows = $this->pdo->query("SELECT DISTINCT source FROM docs")->fetchAll();

        $groups = [];
        foreach ($rows as $row) {
            $parts = explode('/', (string) $row['source']);
            $dir = count($parts) > 1 ? $parts[0] : '(根目录)';
            $file = basename((string) $row['source'], '.md');
            $groups[$dir][] = $file;
        }

        $result = [];
        foreach ($groups as $dir => $files) {
            // 精简文件名为代表关键词（去 KB 编号、下划线、扩展名）
            $keywords = array_slice($files, 0, 12);
            $result[] = [
                'dir' => $dir,
                'description' => self::TOPIC_DESCRIPTIONS[$dir] ?? '',
                'count' => count($files),
                'files' => array_map(fn($f) => str_replace(['.txt', '_', '  '], ['', ' ', ' '], $f), $keywords),
            ];
        }

        // 目录按文件名排序（中文目录用字节序，稳定）
        usort($result, fn($a, $b) => strcmp($a['dir'], $b['dir']));
        return $result;
    }
}