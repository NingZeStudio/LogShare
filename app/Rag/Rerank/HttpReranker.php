<?php

declare(strict_types=1);

namespace App\Rag\Rerank;

/**
 * 专用 cross-encoder 精排器：调用 /rerank 风格的排序端点
 * （Cohere Rerank、Jina Reader、SiliconFlow bge-reranker、vLLM、Xinference 等
 * 共用 {"model","query","documents","top_n"} 请求体）。
 *
 * 与 LLMReranker 的取舍：cross-encoder 一次前向即给出相关性分值，精度与成本
 * 都优于让对话模型做 listwise 重排，但它需要独立端点与密钥。任何失败（未配置、
 * 超时、HTTP >= 400、响应无法解析）都原样返回输入——精排是增强项，绝不能让
 * 检索因它失败，这一点与 LLMReranker 一致。
 */
final class HttpReranker implements RerankInterface
{
    private const CONNECT_TIMEOUT = 5;

    /** 响应体积上限：top-30 的分值列表极小，超限即视为异常响应 */
    private const MAX_RESPONSE_BYTES = 1048576;

    /** 每条候选送入模型的正文字符数上限（cross-encoder 有硬 token 窗口） */
    private const DOC_CHARS = 1000;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $maxCandidates = 30,
        private readonly int $timeout = 10,
    ) {
    }

    public function isActive(): bool
    {
        return true;
    }

    /**
     * @param array<int, array{title: string, body: string, source: string, score: mixed, snippet: string}> $candidates
     * @return array<int, array> 按相关性分值重排后的候选（池外条目保持原顺序接在尾部）
     */
    public function rerank(string $query, array $candidates): array
    {
        if (count($candidates) < 2) {
            return $candidates;
        }

        $pool = array_slice($candidates, 0, $this->maxCandidates);
        try {
            $order = $this->askEndpoint($query, $pool);
        } catch (\Throwable $e) {
            \App\Syslog::error('RAG', 'rerank endpoint failed, keeping RRF order: ' . $e->getMessage());
            return $candidates;
        }
        if ($order === null) {
            return $candidates;
        }

        $out = [];
        $used = [];
        foreach ($order as $idx) {
            if (isset($pool[$idx]) && !isset($used[$idx])) {
                $out[] = $pool[$idx];
                $used[$idx] = true;
            }
        }
        foreach ($pool as $idx => $c) {
            if (!isset($used[$idx])) {
                $out[] = $c;
            }
        }
        return array_merge($out, array_slice($candidates, $this->maxCandidates));
    }

    /**
     * 校验并归一化端点地址。
     *
     * 信任边界与 SemanticClient::provider() 一致：地址来自服务端 Admin 配置，
     * 只拦 http/https 之外的协议与空主机名。区别在于允许一个显式开关放行回环
     * 地址——自托管 cross-encoder（TEI / xinference / ollama）绝大多数就监听在
     * 本机，而回环不外扩到内网，打不通也碰不到别的服务。默认拒绝。
     *
     * @throws \InvalidArgumentException 协议非法，或目标为私网/回环但未开 allowLoopback
     */
    public static function normalizeEndpoint(string $baseUrl, bool $allowLoopback = false): string
    {
        $parts = parse_url(trim($baseUrl));
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            throw new \InvalidArgumentException('Rerank endpoint URL must use HTTP or HTTPS');
        }
        $host = strtolower((string) $parts['host']);
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        $isPrivate = !$isLoopback && filter_var($host, FILTER_VALIDATE_IP) !== false
            && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($isPrivate) {
            throw new \InvalidArgumentException('Rerank endpoint URL targets a private address');
        }
        if ($isLoopback && !$allowLoopback) {
            throw new \InvalidArgumentException('Rerank endpoint targets loopback; set allowLoopback to use a local reranker');
        }
        return rtrim(trim($baseUrl), '/');
    }

    /**
     * 一次 /rerank 调用，返回按相关性降序的 0-based 索引序列。
     *
     * @param array<int, array> $pool
     * @return int[]|null 无法解析时返回 null（调用方回退 RRF 顺序）
     */
    private function askEndpoint(string $query, array $pool): ?array
    {
        $documents = [];
        foreach ($pool as $c) {
            $body = trim((string) ($c['snippet'] !== '' ? $c['snippet'] : ($c['body'] ?? '')));
            $text = preg_replace('/\s+/u', ' ', trim((string) ($c['title'] ?? '')) . '。' . $body) ?? '';
            $documents[] = mb_substr($text, 0, self::DOC_CHARS);
        }

        $decoded = $this->post([
            'model' => $this->model,
            'query' => $query,
            'documents' => $documents,
            'top_n' => count($documents),
        ]);

        return self::parseOrder($decoded, count($pool));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<mixed> 解码后的响应
     */
    private function post(array $payload): array
    {
        $ch = curl_init($this->baseUrl . '/rerank');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => array_filter([
                'Content-Type: application/json',
                $this->apiKey !== '' ? 'Authorization: Bearer ' . $this->apiKey : null,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            // 常驻协程进程：必须即时关闭连接，否则 FD 累积触发 EMFILE
            CURLOPT_FORBID_REUSE => true,
        ]);

        try {
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($body === false || $body === '') {
                throw new \RuntimeException('request failed: ' . ($curlError !== '' ? $curlError : 'empty response'));
            }
            if (strlen((string) $body) > self::MAX_RESPONSE_BYTES) {
                throw new \RuntimeException('response too large');
            }
            if ($httpCode >= 400) {
                throw new \RuntimeException('HTTP ' . $httpCode . ': ' . mb_substr((string) $body, 0, 200));
            }

            $decoded = json_decode((string) $body, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('non-JSON body');
            }

            return $decoded;
        } finally {
            $ch = null;
        }
    }

    /**
     * 从响应中提取排序索引。
     *
     * 兼容两种约定：网关类端点的 {"results":[{"index","relevance_score"}]}，
     * 以及裸数组 [{"index","score"}]。带分值时按分值降序（不信任服务端顺序），
     * 无分值时按响应给出的顺序。有效索引不足 2 个视为失败。
     *
     * @param array<mixed> $decoded
     * @return int[]|null
     */
    public static function parseOrder(array $decoded, int $size): ?array
    {
        $rows = $decoded['results'] ?? (array_is_list($decoded) ? $decoded : null);
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $scored = [];
        $seq = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $idx = $row['index'] ?? null;
            if (!is_numeric($idx)) {
                continue;
            }
            $i = (int) $idx;
            if ($i < 0 || $i >= $size || isset($scored[$i])) {
                continue;
            }
            $score = isset($row['relevance_score']) && is_numeric($row['relevance_score'])
                ? (float) $row['relevance_score']
                : (isset($row['score']) && is_numeric($row['score']) ? (float) $row['score'] : null);
            $scored[$i] = ['score' => $score, 'seq' => $seq++];
        }

        if (count($scored) < 2) {
            return null;
        }

        // 任一行缺分值即整体退化为「信任响应顺序」，避免混排语义不明
        $hasScores = !in_array(null, array_column($scored, 'score'), true);
        if ($hasScores) {
            uksort($scored, fn(int $a, int $b): int => [$scored[$b]['score'], $scored[$b]['seq']] <=> [$scored[$a]['score'], $scored[$a]['seq']]);
        } else {
            uasort($scored, fn(array $a, array $b): int => $a['seq'] <=> $b['seq']);
        }

        return array_keys($scored);
    }
}
