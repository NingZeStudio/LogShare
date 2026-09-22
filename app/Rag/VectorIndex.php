<?php

namespace App\Rag;

/**
 * 向量召回：对 doc_embeddings 中的 packed float32 向量做全库余弦扫描。
 *
 * 只物化 vec blob 参与打分（正文按批取回），LIMIT/OFFSET 分批控制内存峰值。
 * 从 RagSearch 拆出，供 RetrievalPipeline（Step 3）与语义增强复用。
 */
final class VectorIndex
{
    /** 向量余弦扫描的单批行数，控制一次性载入内存的向量总量 */
    private const SCAN_BATCH = 5000;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * 与 query 向量做余弦相似度 Top-N 召回。
     *
     * @param array<int, float> $queryVec
     * @param string|null $topicPrefix When set, the scan JOINs docs and filters
     *                                 source by "<topic>/%" at the SQL level
     *                                 (recall is restricted at the source, so
     *                                 no over-fetch-then-filter is needed)
     * @return array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}>
     */
    public function topByCosine(array $queryVec, int $limit, ?string $topicPrefix = null): array
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
        $offset = 0;
        // 分批扫描向量：万级 chunk × 千维向量一次全量载入会占用数十 MB，
        // LIMIT/OFFSET 分批 + 逐批释放控制内存峰值
        while (true) {
            $scanStmt->execute($topicPrefix !== null
                ? [LexicalIndex::escapeLike($topicPrefix) . '/%', self::SCAN_BATCH, $offset]
                : [self::SCAN_BATCH, $offset]);
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
            if (count($batchRows) < self::SCAN_BATCH) {
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
                'snippet' => SnippetExtractor::extract($row['body'], []),
            ];
        }
        return $hits;
    }

    /**
     * @param array<int, float> $vec
     */
    public static function packVector(array $vec): string
    {
        return pack('g*', ...array_map('floatval', $vec));
    }

    /**
     * @return array<int, float>
     */
    public static function unpackVector(string $blob): array
    {
        $count = intdiv(strlen($blob), 4);
        return $count === 0 ? [] : array_values(unpack('g' . $count, $blob));
    }

    /**
     * @param array<int, float> $vec
     */
    public static function norm(array $vec): float
    {
        $sum = 0.0;
        foreach ($vec as $v) {
            $sum += $v * $v;
        }
        return sqrt($sum);
    }
}
