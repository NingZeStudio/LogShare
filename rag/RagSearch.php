<?php

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
    private PDO $pdo;

    public function __construct(private string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            throw new RuntimeException("RAG database directory does not exist: {$dir}");
        }

        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->ensureSchema();
    }

    public function getPdo(): PDO
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
        $projectRoot = dirname(__DIR__);

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

        return $projectRoot . '/index.db';
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
            throw new RuntimeException("Knowledge directory does not exist: {$knowledgeDir}");
        }

        $this->pdo->exec("DELETE FROM docs");

        $files = 0;
        $chunks = 0;
        $insert = $this->pdo->prepare("INSERT INTO docs(title, body, source) VALUES (?, ?, ?)");

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($knowledgeDir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
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

        return ['files' => $files, 'chunks' => $chunks];
    }

    /**
     * Split a markdown file into chunks on `## ` headings.
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

        $sections = preg_split('/^##\s+(.+)$/m', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($sections === false || count($sections) <= 1) {
            return [[
                'title' => basename($source),
                'body' => $content,
            ]];
        }

        $chunks = [];
        $heading = null;
        // $sections alternates: [preamble, heading1, body1, heading2, body2, ...]
        for ($i = 0; $i < count($sections); $i += 2) {
            $body = $sections[$i];
            if ($heading !== null && trim($body) !== '') {
                $chunks[] = ['title' => $heading, 'body' => trim($body)];
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
     * @return array<int, array{title: string, body: string, source: string, score: string}>
     */
    public function search(string $query, int $k = 5): array
    {
        $k = max(1, min((int) $k, 20));
        $query = trim($query);
        if ($query === '') {
            return [];
        }

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
                ];
            }
        }

        // 2. LIKE fallback (catches CJK phrases and partial substrings).
        //    Title hits rank above body hits; shorter chunks rank first.
        $needle = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';
        $stmt = $this->pdo->prepare(
            "SELECT title, body, source,
                    (CASE WHEN title LIKE ? ESCAPE '\\' THEN 2 ELSE 0 END
                          + CASE WHEN body LIKE ? ESCAPE '\\' THEN 1 ELSE 0 END) AS rank
             FROM docs
             WHERE title LIKE ? ESCAPE '\\' OR body LIKE ? ESCAPE '\\'
             ORDER BY rank DESC, length(body) ASC
             LIMIT " . $k
        );
        $stmt->execute([$needle, $needle, $needle, $needle]);
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
            ];
        }

        return array_slice($results, 0, $k);
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
}