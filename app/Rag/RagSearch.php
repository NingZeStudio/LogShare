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
     * 正文短于该长度（字符）时整段返回。
     *
     * 分块本身按 H2 语义单元切割，绝大多数在 1-2K 字符内——整段返回才能把
     * 「签名 → 含义 → 修复步骤」这类结构完整交给模型；此前 600 的阈值导致
     * 长文档几乎总是走窗口模式，解法部分被丢掉。
     */
    private const SNIPPET_FULL_BODY_LIMIT = 1600;

    /**
     * 超长正文围绕命中词向前/后扩展的最大字符窗口。
     * 实际边界回退到最近的空白/句读（最多回看 200 字符），不硬性要求句子边界，
     * 否则代码与术语密集的英文文档会因边界过密而被掐到几十个字符。
     */
    private const SNIPPET_HALF_WINDOW = 800;
    private const SNIPPET_BOUNDARY_LOOKBACK = 200;

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
        // ── 核心诊断资产 ──
        '日志分析' => '成体系的报错条目库（KB 编号条目），按异常类型归类，每条含现象、原因与解决方案',
        'patterns' => '常见崩溃与故障模式库：mixin 注入失败、内存不足、Java 版本错误、mod 依赖缺失等，按「签名-含义-解决方案」组织',
        'format' => '三大日志文件（crash-report / hs_err_pid / latest.log）的格式解读方法与信号速查',
        'mg-issues' => 'MobileGlues 渲染器专题：定位与架构、关键设置概念、实战 issue 蒸馏与排障决策树',
        'amc-issues' => 'Amethyst 启动器（PojavLauncher 官方续作）专题：官方立场、版本兼容、实战 issue 蒸馏',
        'pgw-issues' => 'Pojav Glow·Worm (PGW) 专题：地位与现状、渲染器武器库、实战 issue 蒸馏',
        'zl2-issues' => 'ZL2 启动器实战案例库：渲染器策略、账户输入联机、Mod 兼容分册',
        'fcl-issues' => 'FCL 启动器实战案例：账户/联机、启动器本体、Mod 兼容、渲染器分册',
        'fcl' => 'FCL 官方文档与非崩溃问题集',
        // ── 启动器/渲染器生态 ──
        'mobileglues' => 'MobileGlues 兼容性矩阵：mod/光影支持矩阵与真实设备实测记录',
        'renderers' => '各渲染器家族专题文档：ANGLE、gl4es 家族、ltw、MobileGlues、Zink/Virgl 的差异与适用场景',
        'launchers' => 'Pojav Glow·Worm (PGW) 专题文档',
        'android-native-lib' => 'Android 原生库（lib 型 mod）加载问题：动态库缺失与插件系统',
        'mobile_launcher' => '手机启动器常识：渲染器选择与 Minecraft 版本对应关系',
        'zl_help' => 'Zalith 启动器用户帮助：账号登录（微软/离线/外置）、版本隔离、mod 加载器等操作说明',
        'zl_control2_help' => 'Zalith 控制布局编辑器帮助：控件层创建、编辑器基本操作、菜单功能',
        'zl_projects' => 'Zalith 项目介绍页（zl1/zl2 主要特点、开源信息、支持与反馈）',
        // ── modloader / 服务端开发文档 ──
        'fabric_develop' => 'Fabric 官方开发文档（含 Mixin、注册、事件、渲染等），用于判断 mod 侧代码与 API 问题',
        'forge' => 'Forge 官方开发文档：访问变换器、BER、事件、注册表、资源、本地化',
        'neoforge' => 'NeoForge 官方开发文档',
        'quilt' => 'Quilt（QSL）开发文档',
        'papermc' => 'PaperMC/Adventure 插件开发文档：Audiences、BossBar 等 API',
        'purpur' => 'Purpur 服务端文档：命令、配置、权限、log4j',
        'geyser' => 'Geyser（基岩互通）文档：Floodgate API、命令、FAQ、配置',
        'glowstone' => 'Glowstone 服务端开发文档：代码风格、实体实现、NBT 操作',
        // ── 其他 ──
        'tools' => '样例崩溃报告（测试素材）',
        // ── 运营内容（明确标注低价值，防止模型空跑）──
        'zl_about' => 'Zalith 站点信息：关于本站、隐私政策、服务条款（运营内容，通常不必检索）',
        'zl_announcement' => 'Zalith 站点公告（如 Discord 停运公告；运营内容，诊断价值低，通常不必检索）',
    ];

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

        try {
            $tmpPdo->beginTransaction();

            $files = 0;
            $chunks = 0;
            $chunkRowids = [];
            $chunkBodies = [];
            $insert = $tmpPdo->prepare("INSERT INTO docs(title, body, source) VALUES (?, ?, ?)");

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($knowledgeDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile() || !in_array(strtolower($fileInfo->getExtension()), ['md', 'txt', 'log'], true)) {
                    continue;
                }

                $content = (string) file_get_contents($fileInfo->getPathname());
                $relative = ltrim(substr($fileInfo->getPathname(), strlen(rtrim($knowledgeDir, '/'))), '/');

                foreach (self::chunkMarkdown($relative, $content) as $chunk) {
                    $insert->execute([$chunk['title'], $chunk['body'], $relative]);
                    $chunkRowids[] = (int) $tmpPdo->lastInsertId();
                    $chunkBodies[] = $chunk['title'] . "\n" . $chunk['body'];
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
                $embedStmt->bindValue(2, self::packVector($vec), \PDO::PARAM_LOB);
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
     * The `# ` page title is preserved and prefixed to each chunk title, so the
     * document's main heading remains searchable.
     *
     * @param string $source
     * @param string $content
     * @return array<int, array{title: string, body: string}>
     */
    public static function chunkMarkdown(string $source, string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        // Preserve the `# ` page title (H1) for searchability
        $docTitle = null;
        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            $docTitle = trim($matches[1]);
        }

        $sections = preg_split('/^##\s+(.+)$/m', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($sections === false || count($sections) <= 1) {
            return [[
                'title' => $docTitle ?? basename($source),
                'body' => $content,
            ]];
        }

        $chunks = [];
        $heading = null;
        // $sections alternates: [preamble, heading1, body1, heading2, body2, ...]
        for ($i = 0; $i < count($sections); $i += 2) {
            $body = $sections[$i];

            if ($heading === null) {
                // Preamble before the first H2: drop the H1 line, keep the intro text
                $preamble = preg_replace('/^#\s+[^\n]*\n?/m', '', $body);
                if (trim($preamble) !== '') {
                    $chunks[] = [
                        'title' => $docTitle ?? basename($source),
                        'body' => trim($preamble),
                    ];
                }
            } elseif (trim($body) !== '') {
                $chunks[] = [
                    'title' => $docTitle !== null ? $docTitle . ' > ' . $heading : $heading,
                    'body' => trim($body),
                ];
            }

            $heading = $sections[$i + 1] ?? null;
        }

        return $chunks;
    }

    /**
     * Escape LIKE wildcards (with backslash as the ESCAPE char).
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
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
        $sourceFilter = '';
        $sourceParams = [];
        if ($topic !== null) {
            $sourceFilter = " AND source LIKE ? ESCAPE '\\'";
            $sourceParams = [self::escapeLike($topic) . '/%'];
        }

        // 候选池：语义精排前多召回一些；纯词法路径仍只输出 k 条
        $pool = max(20, $k * 4);

        $terms = self::splitTerms($query);
        $results = [];
        $seen = [];

        // 1. FTS5 BM25 over English / code tokens with prefix matching.
        //    Strict AND first; when it yields nothing (over-constrained multi-word
        //    queries), degrade to OR ranked by bm25 so partial matches still surface.
        preg_match_all('/[0-9A-Za-z_]+/', $query, $tokenMatches);
        $tokens = array_values(array_unique(array_map('strtolower', $tokenMatches[0])));

        if (!empty($tokens)) {
            $ftsMatches = [implode(' AND ', array_map(fn($t) => $t . '*', $tokens))];
            if (count($tokens) > 1) {
                $ftsMatches[] = implode(' OR ', array_map(fn($t) => $t . '*', $tokens));
            }
            foreach ($ftsMatches as $match) {
                if ($results !== []) {
                    break;
                }
                $stmt = $this->pdo->prepare(
                    "SELECT rowid, title, body, source, bm25(docs, 10.0, 1.0, 1.0) AS rank
                     FROM docs WHERE docs MATCH ?{$sourceFilter} ORDER BY rank LIMIT " . $pool
                );
                $stmt->execute(array_merge([$match], $sourceParams));
                foreach ($stmt->fetchAll() as $row) {
                    $key = $row['source'] . '#' . $row['title'];
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $results[] = [
                        'title' => $row['title'],
                        'body' => $row['body'],
                        'source' => $row['source'],
                        'score' => $row['rank'],
                        'snippet' => self::extractSnippet($row['body'], $terms),
                    ];
                }
            }
        }

        // 2. LIKE fallback for CJK / substring matching. AND semantics first;
        //    empty result degrades to OR ranked by number of matched terms
        //    (title hit = 2, body hit = 1).
        if (!empty($terms)) {
            foreach ([true, false] as $requireAll) {
                if (($results !== [] && $requireAll === false && !empty($tokens)) || ($results !== [] && $requireAll)) {
                    break;
                }
                $rankParts = [];
                $rankParams = [];
                $whereParts = [];
                $whereParams = [];

                foreach ($terms as $term) {
                    $like = '%' . self::escapeLike($term) . '%';
                    $rankParts[] = "(CASE WHEN title LIKE ? ESCAPE '\\' THEN 2 ELSE 0 END + CASE WHEN body LIKE ? ESCAPE '\\' THEN 1 ELSE 0 END)";
                    $rankParams[] = $like;
                    $rankParams[] = $like;
                    $wherePart = "(title LIKE ? ESCAPE '\\' OR body LIKE ? ESCAPE '\\')";
                    if ($requireAll) {
                        $whereParts[] = $wherePart;
                        $whereParams = array_merge($whereParams, [$like, $like]);
                    } else {
                        $whereParts[] = $wherePart;
                        $whereParams = array_merge($whereParams, [$like, $like]);
                    }
                }

                if (!$requireAll) {
                    $whereSql = '(' . implode(' OR ', $whereParts) . ')';
                } else {
                    $whereSql = implode(' AND ', $whereParts);
                }

                $sql = "SELECT rowid, title, body, source, (" . implode(' + ', $rankParts) . ") AS rank
                        FROM docs WHERE ({$whereSql}){$sourceFilter}
                        ORDER BY rank DESC, length(body) ASC LIMIT " . ($results === [] ? $pool : max(5, $pool - count($results)));
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(array_merge($rankParams, $whereParams, $sourceParams));

                foreach ($stmt->fetchAll() as $row) {
                    $key = $row['source'] . '#' . $row['title'];
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $results[] = [
                        'title' => $row['title'],
                        'body' => $row['body'],
                        'source' => $row['source'],
                        'score' => $requireAll ? 'fallback' : 'fallback-or',
                        'snippet' => self::extractSnippet($row['body'], $terms),
                    ];
                }
            }
        }

        // 3. Semantic enhancement: vector recall is primary, lexical results supplement it.
        return $this->applySemanticEnhancement($query, $results, $k, $topic);
    }

    /**
     * Vector-recall candidates are primary and lexical results supplement them. Any failure logs and returns the
     * lexical-only slice — semantic search must never break retrieval.
     *
     * @param array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}> $lexical
     * @return array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}>
     */
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
    /** 向量余弦扫描的单批行数，控制一次性载入内存的向量总量 */
    private const VECTOR_SCAN_BATCH = 5000;

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
            $vectorHits = $this->topByCosine($queryVec, max(20, $k * 4), $topic);
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

    /**
     * Cosine-similarity scan over stored chunk embeddings.
     *
     * Only vec blobs are materialised for scoring (bodies would cost ~MBs per
     * query); metadata for the top hits is fetched in a second round trip.
     * Chunks without an embedding (semantic was off at build time) are skipped.
     *
     * @param array<int, float> $queryVec
     * @param string|null $topicPrefix When set, the scan JOINs docs and filters
     *                                 source by "<topic>/%" at the SQL level
     *                                 (recall is restricted at the source, so
     *                                 no over-fetch-then-filter is needed)
     * @return array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}>
     */
    private function topByCosine(array $queryVec, int $limit, ?string $topicPrefix = null): array
    {
        $qNorm = self::norm($queryVec);
        $dim = count($queryVec);
        $scored = [];
        $dimensionMismatchSeen = false;
        if ($topicPrefix !== null) {
            $scanStmt = $this->pdo->prepare(
                "SELECT e.rowid, e.vec FROM doc_embeddings e
                 JOIN docs d ON d.rowid = e.rowid
                 WHERE d.source LIKE ? ESCAPE '\\'
                 LIMIT ? OFFSET ?"
            );
        } else {
            $scanStmt = $this->pdo->prepare("SELECT e.rowid, e.vec FROM doc_embeddings e LIMIT ? OFFSET ?");
        }
        $batchSize = self::VECTOR_SCAN_BATCH;
        $offset = 0;
        // 分批扫描向量：万级 chunk × 千维向量一次全量载入会占用数十 MB，
        // LIMIT/OFFSET 分批 + 逐批释放控制内存峰值
        while (true) {
            $scanStmt->execute($topicPrefix !== null
                ? [self::escapeLike($topicPrefix) . '/%', $batchSize, $offset]
                : [$batchSize, $offset]);
            $batchRows = $scanStmt->fetchAll();
            if ($batchRows === []) {
                break;
            }
            $offset += count($batchRows);
            foreach ($batchRows as $row) {
                $vec = self::unpackVector((string) $row['vec']);
                if ($vec === []) {
                    continue;
                }
                if (count($vec) !== $dim) {
                    // 历史向量与当前 embedding 模型维度不一致（如切换模型后未重建索引）
                    $dimensionMismatchSeen = true;
                    continue;
                }
                $dot = 0.0;
                foreach ($queryVec as $i => $qv) {
                    $dot += $qv * $vec[$i];
                }
                $vNorm = self::norm($vec);
                if ($qNorm == 0.0 || $vNorm == 0.0) {
                    continue;
                }
                $scored[] = ['rowid' => (int) $row['rowid'], 'sim' => $dot / ($qNorm * $vNorm)];
            }
            if (count($batchRows) < $batchSize) {
                break;
            }
        }

        if ($dimensionMismatchSeen) {
            static $warnedOnce = false;
            if (!$warnedOnce) {
                $warnedOnce = true;
                \App\Syslog::error('RAG', "stored embeddings have a different dimension than the current model ({$dim}) — they are being ignored; re-run rag:build to re-embed");
            }
        }

        usort($scored, fn($a, $b) => $b['sim'] <=> $a['sim']);

        // prepare 提到循环外，避免同一 SQL 重复编译
        $metaStmt = $this->pdo->prepare("SELECT title, body, source FROM docs WHERE rowid = ?");
        $hits = [];
        foreach (array_slice($scored, 0, $limit) as $s) {
            $metaStmt->execute([$s['rowid']]);
            $row = $metaStmt->fetch();
            if ($row === false) {
                continue;
            }
            $hits[] = [
                'title' => $row['title'],
                'body' => $row['body'],
                'source' => $row['source'],
                'score' => 'vector:' . round($s['sim'], 4),
                'snippet' => self::extractSnippet($row['body'], []),
            ];
        }
        return $hits;
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
     * @param array<int, float> $vec
     */
    private static function packVector(array $vec): string
    {
        return pack('g*', ...array_map('floatval', $vec));
    }

    private static function unpackVector(string $blob): array
    {
        $count = intdiv(strlen($blob), 4);
        return $count === 0 ? [] : array_values(unpack('g' . $count, $blob));
    }

    /**
     * @param array<int, float> $vec
     */
    private static function norm(array $vec): float
    {
        $sum = 0.0;
        foreach ($vec as $v) {
            $sum += $v * $v;
        }
        return sqrt($sum);
    }

    /**
     * 围绕命中词提取上下文片段。
     *
     * 取舍策略：
     *  - 短正文（≤ SNIPPET_FULL_BODY_LIMIT）整段返回——分块按 H2 切割，
     *    整段才能保住「签名 → 含义 → 修复步骤」这类结构完整性；
     *  - 超长正文围绕第一个命中词取 ±SNIPPET_HALF_WINDOW 硬窗口，
     *    再向内回退到最近的空白/句读做整洁断点；找不到边界时用硬窗口，
     *    绝不允许出现几十字符的过短片段。
     *
     * @param string $body
     * @param array<int, string> $terms
     * @return string
     */
    private static function extractSnippet(string $body, array $terms): string
    {
        $bodyLen = mb_strlen($body);
        if ($bodyLen === 0) {
            return '';
        }

        if ($bodyLen <= self::SNIPPET_FULL_BODY_LIMIT) {
            return $body;
        }

        $hitPos = null;
        $hitLen = 0;
        foreach ($terms as $term) {
            if (mb_strlen($term) < 2) {
                continue; // bigram 噪声项不作为窗口锚点
            }
            $pos = mb_stripos($body, $term);
            if ($pos !== false && ($hitPos === null || $pos < $hitPos)) {
                $hitPos = $pos;
                $hitLen = mb_strlen($term);
            }
        }

        // 命中标题、正文无词时，返回正文开头片段
        if ($hitPos === null) {
            return mb_substr($body, 0, self::SNIPPET_HALF_WINDOW) . '…';
        }

        // 硬窗口 + 向内找最近的空白/句读做整洁断点（最多回看 BOUNDARY_LOOKBACK）
        $start = max(0, $hitPos - self::SNIPPET_HALF_WINDOW);
        $start = self::retreatToBoundary($body, $start, min($hitPos, $start + self::SNIPPET_BOUNDARY_LOOKBACK));

        $end = min($bodyLen, $hitPos + $hitLen + self::SNIPPET_HALF_WINDOW);
        $end = self::advanceToBoundary($body, max($end - self::SNIPPET_BOUNDARY_LOOKBACK, $hitPos + $hitLen), $end);

        return ($start > 0 ? '…' : '')
            . trim(mb_substr($body, $start, $end - $start))
            . ($end < $bodyLen ? "\n…" : '');
    }

    /**
     * From $from, walk forward to the first blank/sentence boundary at or before
     * $to. Returns $to when no boundary is found in range.
     */
    private static function retreatToBoundary(string $body, int $from, int $to): int
    {
        for ($i = $from; $i < $to; $i++) {
            if (self::isSnippetBreak(mb_substr($body, $i, 1))) {
                return $i;
            }
        }
        return $to;
    }

    private static function advanceToBoundary(string $body, int $from, int $to): int
    {
        for ($i = $to - 1; $i >= max($from, 0); $i--) {
            if (self::isSnippetBreak(mb_substr($body, $i, 1))) {
                return $i;
            }
        }
        return $to;
    }

    private static function isSnippetBreak(string $ch): bool
    {
        // 空白与句读都可作为断点：保留换行即保留 Markdown 列表结构
        return trim($ch) === '' || in_array($ch, ['。', '！', '？', '；', '.', '!', '?', ';'], true);
    }

    /**
     * Split a query into distinct non-empty terms on whitespace and punctuation.
     *
     * CJK runs of 3+ characters are additionally exploded into overlapping
     * bigrams (数据包导致失败 → 数据/据包/包导/...): there is no CJK word
     * segmentation, so the original run as a single LIKE term almost never
     * matches; bigrams let the OR-fallback rank documents by how many
     * fragments they contain, which correlates well with relevance.
     *
     * @param string $query
     * @return array<int, string>
     */
    private static function splitTerms(string $query): array
    {
        $terms = preg_split('/[\s,，、;；:：.。!！?？\t]+/u', $query);
        if ($terms === false) {
            return [];
        }

        $terms = array_values(array_unique(array_filter(array_map('trim', $terms), fn($t) => $t !== '')));

        $withBigrams = [];
        foreach ($terms as $term) {
            $withBigrams[] = $term;
            if (preg_match_all('/[\x{4e00}-\x{9fff}]{2,}/u', $term, $runs) !== 0) {
                foreach ($runs[0] as $run) {
                    $len = mb_strlen($run);
                    if ($len < 3) {
                        continue;
                    }
                    for ($i = 0; $i + 2 <= $len; $i++) {
                        $withBigrams[] = mb_substr($run, $i, 2);
                    }
                }
            }
        }

        return array_values(array_unique($withBigrams));
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