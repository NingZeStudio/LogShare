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
     * 必须写清楚内容与诊断价值；运营类内容标注「通常不必检索」以免模型空跑。
     * 新增知识库目录时必须在此登记（与 scripts/clean_knowledge_docs.php 的
     * UPSTREAM_DIRS 白名单约定并行）；未登记目录回退为文件名样本展示（见 topics()）。
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
        // ── 其他 ──
        'tools' => '样例崩溃报告（测试素材）',
        // ── 运营内容（明确标注低价值，防止模型空跑）──
        'zl_about' => 'Zalith 站点信息：关于本站、隐私政策、服务条款（运营内容，通常不必检索）',
        'zl_announcement' => 'Zalith 站点公告（如 Discord 停运公告；运营内容，诊断价值低，通常不必检索）',
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
            $embedStmt = $tmpPdo->prepare("INSERT OR REPLACE INTO doc_embeddings(rowid, vec) VALUES (?, ?)");

            $storeEmbedding = function (int $rowid, array $vec) use ($embedStmt, &$embedded): void {
                $embedStmt->bindValue(1, $rowid, \PDO::PARAM_INT);
                $embedStmt->bindValue(2, VectorIndex::packVector($vec), \PDO::PARAM_LOB);
                $embedStmt->execute();
                $embedded++;
            };

            $embedSingle = function (int $rowid, string $text) use ($semantic, $storeEmbedding): bool {
                $text = trim(mb_strcut($text, 0, 4000));
                if ($text === '') {
                    return false;
                }
                try {
                    $vec = $semantic->embed([$text])[0] ?? null;
                    if ($vec === null) {
                        return false;
                    }
                    $storeEmbedding($rowid, $vec);
                    return true;
                } catch (\Throwable) {
                    return false;
                }
            };

            $batchSize = 16;
            $pairs = array_map(null, $chunkRowids, $chunkBodies);
            foreach (array_chunk($pairs, $batchSize) as $i => $batch) {
                $texts = array_map(fn($p) => trim(mb_strcut((string) $p[1], 0, 4000)), $batch);

                try {
                    $vectors = $semantic->embed($texts);
                    foreach ($batch as $j => [$rowid,]) {
                        if (!isset($vectors[$j]) || trim($texts[$j]) === '') {
                            continue;
                        }
                        $storeEmbedding($rowid, $vectors[$j]);
                    }
                } catch (\Throwable $e) {
                    \App\Syslog::error('RAG', 'embedding batch #' . $i . ' failed (' . $e->getMessage() . '), retrying per chunk');
                    foreach ($batch as $j => [$rowid, $body]) {
                        if (!$embedSingle($rowid, $body)) {
                            \App\Syslog::error('RAG', "chunk rowid={$rowid} skipped: unembeddable");
                        }
                    }
                }
            }
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
        $k = max(1, min((int) $k, 20));
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        // 防御性二次归一化（幂等）：控制器已校验过，直接内部调用同样生效
        $topic = self::normalizeTopic($topic);
        $originalQuery = $query;

        // 查询预处理（ai.rag.queryRewrite.enabled，默认关闭 → 逐字节不变）：
        // 改写只喂给检索通道，embed/缓存仍用原查询；分类偏置只在无显式
        // topic 时生效，模型圈定的目录优先级高于规则猜测。
        $pre = ['query' => $query, 'weights' => [], 'rewritten' => false];
        if (QueryPreProcessor::enabled()) {
            $pre = QueryPreProcessor::preprocess($query);
        }

        // 候选池：语义精排前多召回一些；纯词法路径仍只输出 k 条
        $pool = max(20, $k * 4);

        $results = (new LexicalIndex($this->pdo))->search($pre['query'], self::splitTerms($pre['query']), $pool, $topic);

        // Semantic enhancement: vector recall is primary, lexical results supplement it.
        // 语义召回与结果缓存锚定原始查询（改写词只服务词法通道）。分类偏置生效时
        // 扩大语义截断量到 pool、偏置后再截 k；默认路径仍按 k 截断，缓存键不变。
        $biasable = $topic === null && $pre['weights'] !== [];
        $final = $this->applySemanticEnhancement($originalQuery, $results, $biasable ? $pool : $k, $topic);

        if ($biasable) {
            $final = QueryPreProcessor::applyTopicBias($final, $pre['weights']);
        }

        return array_slice($final, 0, $k);
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

    private function applySemanticEnhancement(string $query, array $lexical, int $k, ?string $topic = null): array
    {
        $client = self::semanticClientFromConfig();
        if ($client === null || !$client->isConfigured()) {
            return array_slice($lexical, 0, $k);
        }

        // 缓存键必须含 topic：不同目录同名 query 的结果不可互串
        $cacheKey = 'semantic-v2:' . md5($query) . ':' . $k . ':' . ($topic ?? '');
        $cached = self::$semanticCache[$cacheKey] ?? null;
        if ($cached !== null && $cached['expires'] > time()) {
            return $cached['results'];
        }

        try {
            $results = $this->runSemanticPipeline($query, $lexical, $k, $client, $topic);
        } catch (\Throwable $e) {
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
     * @param array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}> $lexical
     * @return array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}>
     */
    private function runSemanticPipeline(string $query, array $lexical, int $k, SemanticClient $client, ?string $topic = null): array
    {
        try {
            $queryVec = $client->embed([$query])[0] ?? null;
            if ($queryVec === null) {
                throw new \RuntimeException('empty query embedding');
            }

            // 向量召回：与全库嵌入算余弦，补足词法漏掉的同义表述；
            // topic 模式下过滤下推到召回 SQL（源头限定目录，无需扩量放大）
            $vectorHits = (new VectorIndex($this->pdo))->topByCosine($queryVec, max(20, $k * 4), $topic);

            // rerank 开关开启：走 RetrievalPipeline（RRF 融合 + LLM 精排）。
            // 关闭时保持既有「向量优先、词法补充」的截断合并，逐字节不变。
            if (self::rerankEnabled()) {
                $pipeline = new RetrievalPipeline(self::rerankerFromConfig());
                return $pipeline->retrieve($query, $lexical, $vectorHits, $k)['results'];
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
     * 构造精排器：有可用 AI 密钥时返回 LLMReranker，否则 Noop（仅 RRF）。
     */
    private static function rerankerFromConfig(): Rerank\RerankInterface
    {
        $ai = [];
        try {
            $ai = (array) \App\Config::Get('ai');
        } catch (\Throwable) {
            $ai = [];
        }
        $hasKeys = !empty($ai['apiKeys']) || !empty($ai['apiKey']);
        if (!$hasKeys) {
            return new Rerank\NoopReranker();
        }
        $max = (int) ($ai['rag']['rerank']['maxCandidates'] ?? 30);
        return new Rerank\LLMReranker(max(2, min($max, 50)));
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
            $providers[] = SemanticClient::provider(
                (string) ($p['name'] ?? $p['baseUrl']),
                (string) $p['baseUrl'],
                (string) ($p['apiKey'] ?? ''),
                (string) ($p['embeddingModel'] ?? ($cfg['embeddingModel'] ?? 'bge-m3')),
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
     * 围绕命中词提取上下文片段。
     *
     * 委托 SnippetExtractor；保留本静态方法是因为既有测试通过反射使用它
     * （语义不变）。
     *
     * @param string $body
     * @param array<int, string> $terms
     * @return string
     */
    private static function extractSnippet(string $body, array $terms): string
    {
        return SnippetExtractor::extract($body, $terms);
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