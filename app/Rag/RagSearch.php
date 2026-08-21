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
     * 正文短于该长度（字符）时整段返回，优先保证上下文理解。
     */
    private const SNIPPET_FULL_BODY_LIMIT = 600;

    /**
     * 长正文围绕命中词向前/后各扩展的最大字符窗口；实际以句子边界为断点，
     * 长度随内容自然变化，不固定。
     */
    private const SNIPPET_HALF_WINDOW = 250;

    private \PDO $pdo;

    public function __construct(private string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            throw new \RuntimeException("RAG database directory does not exist: {$dir}");
        }

        $this->pdo = new \PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->ensureSchema();
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Resolve the SQLite database path.
     *
     * Priority: RAG_DB_PATH env var (dev/tests override) > ai.mcp.rag.db in
     * Config.inc.php > default <project>/rag/index.db.
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

        $configFile = $projectRoot . '/Config.inc.php';
        if (file_exists($configFile)) {
            $data = require $configFile;
            $db = $data['ai']['mcp']['rag']['db'] ?? null;
            if (is_string($db) && $db !== '') {
                return str_starts_with($db, '/') ? $db : $projectRoot . '/' . $db;
            }
        }

        return $projectRoot . '/rag/index.db';
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec(
            "CREATE VIRTUAL TABLE IF NOT EXISTS docs USING fts5(
                title,
                body,
                source,
                tokenize = 'porter unicode61'
            )"
        );
    }

    /**
     * Index every supported file under a directory (recursively).
     *
     * The index is rebuilt from scratch so stale chunks never linger.
     *
     * @param string $knowledgeDir
     * @return array{files: int, chunks: int}
     */
    public function buildIndex(string $knowledgeDir): array
    {
        if (!is_dir($knowledgeDir)) {
            throw new \RuntimeException("Knowledge directory does not exist: {$knowledgeDir}");
        }

        $this->pdo->beginTransaction();

        try {
            $this->pdo->exec("DELETE FROM docs");

            $files = 0;
            $chunks = 0;
            $insert = $this->pdo->prepare("INSERT INTO docs(title, body, source) VALUES (?, ?, ?)");

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($knowledgeDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $fileInfo) {
                /** @var \SplFileInfo $fileInfo */
                if (!$fileInfo->isFile() || !in_array(strtolower($fileInfo->getExtension()), ['md', 'txt', 'log'], true)) {
                    continue;
                }

                $content = (string) file_get_contents($fileInfo->getPathname());
                $relative = ltrim(substr($fileInfo->getPathname(), strlen(rtrim($knowledgeDir, '/'))), '/');

                foreach (self::chunkMarkdown($relative, $content) as $chunk) {
                    $insert->execute([$chunk['title'], $chunk['body'], $relative]);
                    $chunks++;
                }
                $files++;
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['files' => $files, 'chunks' => $chunks];
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

        $terms = self::splitTerms($query);
        $results = [];
        $seen = [];

        // 1. FTS5 BM25 over English / code tokens with prefix matching
        preg_match_all('/[0-9A-Za-z_]+/', $query, $tokenMatches);
        $tokens = array_values(array_unique(array_map('strtolower', $tokenMatches[0])));

        if (!empty($tokens)) {
            $match = implode(' AND ', array_map(fn($t) => $t . '*', $tokens));
            $stmt = $this->pdo->prepare(
                "SELECT title, body, source, bm25(docs, 10.0, 1.0, 1.0) AS rank
                 FROM docs WHERE docs MATCH ? ORDER BY rank LIMIT " . $k
            );
            $stmt->execute([$match]);
            foreach ($stmt->fetchAll() as $row) {
                $key = $row['source'] . '#' . $row['title'];
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

        // 2. LIKE fallback with term splitting (AND semantics for CJK multi-keyword queries).
        //    Each term must appear in the title or body; title hits rank above body hits.
        if (!empty($terms)) {
            $rankParts = [];
            $rankParams = [];
            $whereParts = [];
            $whereParams = [];

            foreach ($terms as $term) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term) . '%';
                $rankParts[] = "(CASE WHEN title LIKE ? ESCAPE '\\' THEN 2 ELSE 0 END + CASE WHEN body LIKE ? ESCAPE '\\' THEN 1 ELSE 0 END)";
                $rankParams[] = $like;
                $rankParams[] = $like;
                $whereParts[] = "(title LIKE ? ESCAPE '\\' OR body LIKE ? ESCAPE '\\')";
                $whereParams[] = $like;
                $whereParams[] = $like;
            }

            $sql = "SELECT title, body, source, (" . implode(' + ', $rankParts) . ") AS rank
                    FROM docs WHERE " . implode(' AND ', $whereParts) . "
                    ORDER BY rank DESC, length(body) ASC LIMIT " . $k;
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
                    'score' => 'fallback',
                    'snippet' => self::extractSnippet($row['body'], $terms),
                ];
            }
        }

        return array_slice($results, 0, $k);
    }

    /**
     * 围绕命中词提取上下文片段。
     *
     * 取舍策略：
     *  - 短正文（≤ SNIPPET_FULL_BODY_LIMIT）整段返回，保上下文理解；
     *  - 长正文围绕第一个命中词，向前/后扩展到最近的句子边界
     *    （句号/问号/叹号/换行），命中词始终位于片段中央，保搜索细度；
     *  - 窗口以句子边界为自然断点，长度随内容变化，不硬编码固定字节数。
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

        $start = $hitPos;
        while ($start > 0 && $hitPos - $start < self::SNIPPET_HALF_WINDOW) {
            if (self::isSentenceBoundary(mb_substr($body, $start - 1, 1))) {
                break;
            }
            $start--;
        }

        $end = $hitPos + $hitLen;
        while ($end < $bodyLen && $end - $hitPos < self::SNIPPET_HALF_WINDOW) {
            $end++;
            if (self::isSentenceBoundary(mb_substr($body, $end - 1, 1))) {
                break;
            }
        }

        return ($start > 0 ? '…' : '')
            . mb_substr($body, $start, $end - $start)
            . ($end < $bodyLen ? '…' : '');
    }

    private static function isSentenceBoundary(string $ch): bool
    {
        // 仅句末标点作为句子边界；换行不作边界（Markdown 列表项/行内换行不代表句子结束）
        return in_array($ch, ['。', '！', '？', '；', '.', '!', '?', ';'], true);
    }

    /**
     * Split a query into distinct non-empty terms on whitespace and punctuation.
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

        return array_values(array_unique(array_filter(array_map('trim', $terms), fn($t) => $t !== '')));
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