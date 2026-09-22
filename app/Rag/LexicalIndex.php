<?php

namespace App\Rag;

/**
 * 词法召回：FTS5 BM25（英文/代码 token 前缀匹配）+ LIKE 兜底（CJK/子串）。
 *
 * 从 RagSearch 拆出的独立组件——Step 3 的 RetrievalPipeline 将对它做
 * 并行召回 + RRF 融合，故保持「给 PDO 和 query 就能召回」的无状态调用形态。
 */
final class LexicalIndex
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * 词法检索主入口：FTS AND→OR 降级后接 LIKE AND→OR 降级。
     *
     * @param array<int, string> $terms splitTerms 产物（含 CJK bigram）
     * @param int $pool 候选池上限
     * @param string|null $topic 目录前缀过滤（调用方负责 normalizeTopic）
     * @return array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}>
     */
    public function search(string $query, array $terms, int $pool, ?string $topic = null): array
    {
        $sourceFilter = '';
        $sourceParams = [];
        if ($topic !== null) {
            $sourceFilter = " AND source LIKE ? ESCAPE '\\'";
            $sourceParams = [self::escapeLike($topic) . '/%'];
        }

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
                        'snippet' => SnippetExtractor::extract($row['body'], $terms),
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
                    $whereParts[] = $wherePart;
                    $whereParams = array_merge($whereParams, [$like, $like]);
                }

                $whereSql = $requireAll
                    ? implode(' AND ', $whereParts)
                    : '(' . implode(' OR ', $whereParts) . ')';

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
                        'snippet' => SnippetExtractor::extract($row['body'], $terms),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Escape LIKE wildcards (with backslash as the ESCAPE char).
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
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
    public static function splitTerms(string $query): array
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
}
