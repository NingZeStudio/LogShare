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
     * Search the knowledge base.
     *
     * @param string $query
     * @param int $k Maximum number of results
     * @return array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}>
     */
    public function search(string $query, int $k = 5): array
    {
        $k = max(1, min((int) $k, 20));
        $query = trim($query);
        if ($query === '') {
            return [];
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
                     FROM docs WHERE docs MATCH ? ORDER BY rank LIMIT " . $pool
                );
                $stmt->execute([$match]);
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
                    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term) . '%';
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
                        FROM docs WHERE {$whereSql}
                        ORDER BY rank DESC, length(body) ASC LIMIT " . ($results === [] ? $pool : max(5, $pool - count($results)));
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(array_merge($rankParams, $whereParams));

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

        // 3. Semantic enhancement: vector recall widens the candidate pool,
        // then the bge-reranker-v2-m3 decides the final order.
        return $this->applySemanticEnhancement($query, $results, $k);
    }

    /**
     * Vector-recall extra candidates, merge them into the lexical ones and let
     * the reranker decide the final order. Any failure logs and returns the
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
    private const SEMANTIC_CACHE_TTL = 60;
    private const SEMANTIC_CACHE_MAX = 64;
    private const SEMANTIC_CACHE_MAX_BYTES = 1048576;

    private function applySemanticEnhancement(string $query, array $lexical, int $k): array
    {
        $client = self::semanticClientFromConfig();
        if ($client === null || !$client->isConfigured()) {
            return array_slice($lexical, 0, $k);
        }

        $cacheKey = 'semantic-v2:' . md5($query) . ':' . $k;
        $cached = self::$semanticCache[$cacheKey] ?? null;
        if ($cached !== null && $cached['expires'] > time()) {
            return $cached['results'];
        }

        try {
            $results = $this->runSemanticPipeline($query, $lexical, $k, $client);
        } catch (\Throwable $e) {
            \App\Syslog::error('RAG', 'semantic enhancement failed, falling back to lexical: ' . $e->getMessage());
            return array_slice($lexical, 0, $k);
        }

        $entry = ['expires' => time() + self::SEMANTIC_CACHE_TTL, 'results' => $results];
        while (self::$semanticCache !== [] && (count(self::$semanticCache) >= self::SEMANTIC_CACHE_MAX || strlen(serialize(self::$semanticCache)) + strlen(serialize($entry)) > self::SEMANTIC_CACHE_MAX_BYTES)) {
            array_shift(self::$semanticCache);
        }
        if (strlen(serialize($entry)) <= self::SEMANTIC_CACHE_MAX_BYTES) {
            self::$semanticCache[$cacheKey] = $entry;
        }

        return $results;
    }

    /**
     * Vector-recall extra candidates, merge them into the lexical ones and let
     * the reranker decide the final order. Any failure logs and returns the
     * lexical-only slice — semantic search must never break retrieval.
     *
     * @param array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}> $lexical
     * @return array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}>
     */
    private function runSemanticPipeline(string $query, array $lexical, int $k, SemanticClient $client): array
    {
        try {
            $queryVec = $client->embed([$query])[0] ?? null;
            if ($queryVec === null) {
                throw new \RuntimeException('empty query embedding');
            }

            // 向量召回：与全库嵌入算余弦，补足词法漏掉的同义表述
            $vectorHits = $this->topByCosine($queryVec, max(20, $k * 4));
            $mergedKeys = [];
            foreach ($lexical as $r) {
                $mergedKeys[$r['source'] . '#' . $r['title']] = true;
            }
            foreach ($vectorHits as $hit) {
                $key = $hit['source'] . '#' . $hit['title'];
                if (!isset($mergedKeys[$key])) {
                    $mergedKeys[$key] = true;
                    $lexical[] = $hit;
                }
            }
            $merged = array_values($lexical);

            if (count($merged) <= 1) {
                return array_slice($merged, 0, $k);
            }

            $docs = array_map(fn($r) => $r['title'] . "\n" . $r['body'], $merged);
            $ranked = $client->rerank($query, $docs, $k);

            $out = [];
            foreach ($ranked as $entry) {
                $idx = $entry['index'];
                if (!isset($merged[$idx])) {
                    continue;
                }
                $merged[$idx]['score'] = round($entry['score'], 4);
                $out[] = $merged[$idx];
            }

            // 兜底：网关偶发返回空 results（限流/异常 query）时不抛异常也不清空，
            // 词法召回的结果必须保住 —— 语义增强绝不能让检索「归零」。
            if ($out === []) {
                \App\Syslog::error('RAG', 'rerank returned no results, falling back to lexical order');
                return array_slice($lexical, 0, $k);
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
     * @return array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}>
     */
    private function topByCosine(array $queryVec, int $limit): array
    {
        $rows = $this->pdo->query(
            "SELECT e.rowid, e.vec FROM doc_embeddings e"
        )->fetchAll();

        $qNorm = self::norm($queryVec);
        $dim = count($queryVec);
        $scored = [];
        $dimensionMismatchSeen = false;
        foreach ($rows as $row) {
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

        if ($dimensionMismatchSeen) {
            static $warnedOnce = false;
            if (!$warnedOnce) {
                $warnedOnce = true;
                \App\Syslog::error('RAG', "stored embeddings have a different dimension than the current model ({$dim}) — they are being ignored; re-run rag:build to re-embed");
            }
        }

        usort($scored, fn($a, $b) => $b['sim'] <=> $a['sim']);

        $hits = [];
        foreach (array_slice($scored, 0, $limit) as $s) {
            $meta = $this->pdo->prepare("SELECT title, body, source FROM docs WHERE rowid = ?");
            $meta->execute([$s['rowid']]);
            $row = $meta->fetch();
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
                (string) ($p['rerankModel'] ?? ($cfg['rerankModel'] ?? 'bge-reranker-v2-m3')),
            );
        }

        // legacy flat config → single provider
        if ($providers === [] && ($cfg['baseUrl'] ?? '') !== '') {
            $providers[] = SemanticClient::provider(
                'default',
                (string) $cfg['baseUrl'],
                (string) ($cfg['apiKey'] ?? ''),
                (string) ($cfg['embeddingModel'] ?? 'bge-m3'),
                (string) ($cfg['rerankModel'] ?? 'bge-reranker-v2-m3'),
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
     * @return array<int, array{dir: string, count: int, files: array<int, string>}>
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
                'count' => count($files),
                'files' => array_map(fn($f) => str_replace(['.txt', '_', '  '], ['', ' ', ' '], $f), $keywords),
            ];
        }

        // 目录按文件名排序（中文目录用字节序，稳定）
        usort($result, fn($a, $b) => strcmp($a['dir'], $b['dir']));
        return $result;
    }
}