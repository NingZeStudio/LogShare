<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * Client for semantic-RAG embedding models such as bge-m3.
 * with ordered multi-provider failover.
 *
 * Providers are tried in configured order. Any transport error or HTTP >= 400 moves to the next provider;
 * a provider without an apiKey is skipped. Model ids are per-provider because
 * gateways name the same open models differently (BAAI/bge-m3 on SiliconFlow,
 * plain bge-m3 elsewhere).
 *
 * Endpoints follow the widely-adopted gateway conventions:
 *   POST {baseUrl}/embeddings   {"model": ..., "input": [...]}
 *
 * Local Ollama providers (SemanticClient::ollama()) use the native format
 * instead: POST /api/embeddings {"model": ..., "prompt": ...} → {"embedding":
 * [...]}（单条请求，逐条循环）。本地回环地址免鉴权。
 *
 * 非 final：测试子类可覆写 embed() 注入确定性向量源（batch 自适应逻辑验证）。
 */
class SemanticClient
{
    private const CONNECT_TIMEOUT = 10;
    private const MAX_RESPONSE_BYTES = 2097152;

    /**
     * @param array<int, array{name: string, baseUrl: string, apiKey: string, embeddingModel: string, local?: bool}> $providers
     */
    public function __construct(
        private array $providers,
        private int $timeout = 30,
    ) {
    }

    public static function provider(string $name, string $baseUrl, string $apiKey, string $embeddingModel): array
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            throw new \InvalidArgumentException('Semantic provider URL must use HTTP or HTTPS');
        }
        $host = strtolower((string) $parts['host']);
        // 与 MCPClient 相同的信任边界：URL 来自服务端配置（ai.rag.providers），
        // 仅拦截私网 IP 字面量与 .local；域名解析结果不做校验（见 MCPClient 注释）。
        // 注意 && 与 || 混用处已加括号，避免可读性问题。
        if ($host === 'localhost' || str_ends_with($host, '.local') || (filter_var($host, FILTER_VALIDATE_IP) !== false && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            throw new \InvalidArgumentException('Semantic provider URL targets a private address');
        }
        return [
            'name' => $name,
            'baseUrl' => rtrim($baseUrl, '/'),
            'apiKey' => trim($apiKey),
            'embeddingModel' => $embeddingModel,
            'local' => false,
        ];
    }

    /**
     * 本地 Ollama provider（plan 3.7：完全离线部署）。
     *
     * 与 provider() 的私网拦截不同：这里只放行 loopback 字面量
     * （localhost / 127.0.0.1 / [::1]），其余主机名仍拒绝——Ollama 默认
     * 就监听 127.0.0.1:11434，不放宽到任意外部地址。免鉴权（apiKey 为空）。
     */
    public static function ollama(string $name, string $baseUrl, string $embeddingModel): array
    {
        $parts = parse_url($baseUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'http' || !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new \InvalidArgumentException('Ollama provider URL must be http on loopback (localhost/127.0.0.1/::1)');
        }
        return [
            'name' => $name,
            'baseUrl' => rtrim($baseUrl, '/'),
            'apiKey' => '',
            'embeddingModel' => $embeddingModel,
            'local' => true,
        ];
    }

    /**
     * Whether semantic enhancement can actually run (keyed provider, or a
     * trusted local Ollama which needs no api key).
     */
    public function isConfigured(): bool
    {
        foreach ($this->providers as $p) {
            if ($p['apiKey'] !== '' || !empty($p['local'])) {
                return true;
            }
        }
        return false;
    }

    /** Name of the provider that answered the last request (for logging). */
    private ?string $lastProviderUsed = null;

    public function getLastProviderUsed(): ?string
    {
        return $this->lastProviderUsed;
    }

    /** 上一次成功响应的向量维度（首次请求前为 null），供维度漂移告警用 */
    private ?int $lastDims = null;

    public function getLastDims(): ?int
    {
        return $this->lastDims;
    }

    /**
     * One-line summary of the usable providers and their embedding models,
     * e.g. "siliconflow/BAAI/bge-m3 -> huidev/bge-m3".
     */
    public function describe(): string
    {
        $parts = [];
        foreach ($this->providers as $p) {
            if ($p['apiKey'] === '' && empty($p['local'])) {
                continue;
            }
            $parts[] = $p['name'] . '/' . $p['embeddingModel'];
        }
        return implode(' -> ', $parts);
    }

    /**
     * Embed a batch of texts; returns one vector per input text.
     *
     * @param array<int, string> $texts
     * @return array<int, array<int, float>>
     * @throws \RuntimeException when every provider fails
     */
    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $usable = array_values(array_filter($this->providers, fn($p) => $p['apiKey'] !== '' || !empty($p['local'])));
        if ($usable !== [] && !empty($usable[0]['local'])) {
            // 首选可用 provider 是本地 Ollama → 原生单条协议
            $vectors = $this->embedOllama($usable[0], $texts);
            $this->lastProviderUsed = $usable[0]['name'];
            return $vectors;
        }

        $body = $this->postWithFailover('embeddingModel', '/embeddings', [
            'model' => null, // filled per-provider
            'input' => array_values($texts),
        ]);

        $data = $body['data'] ?? null;
        if (!is_array($data) || count($data) !== count($texts)) {
            throw new \RuntimeException('Unexpected embeddings response shape');
        }

        // Sort by index — gateways may return embeddings out of order
        $vectors = [];
        foreach ($data as $i => $item) {
            $vec = $item['embedding'] ?? null;
            if (!is_array($vec)) {
                throw new \RuntimeException("Embedding #{$i} missing in response");
            }
            $idx = is_int($item['index'] ?? null) ? $item['index'] : $i;
            $vectors[$idx] = array_map('floatval', $vec);
        }
        ksort($vectors);
        $vectors = array_values($vectors);

        // $texts 非空且响应条数已校验 → $vectors 必非空
        $this->lastDims = count($vectors[0]);
        return $vectors;
    }

    /**
     * Ollama 原生 /api/embeddings 是单条协议（prompt → embedding），批量
     * 输入在这里逐条循环；任意一条失败即整体抛出（与远程批量语义一致，
     * 由调用方降批/逐条重试）。
     *
     * @param array{name: string, baseUrl: string, apiKey: string, embeddingModel: string} $provider
     * @param array<int, string> $texts
     * @return array<int, array<int, float>>
     */
    private function embedOllama(array $provider, array $texts): array
    {
        $vectors = [];
        foreach ($texts as $text) {
            $body = $this->postTo($provider, '/api/embeddings', [
                'model' => $provider['embeddingModel'],
                'prompt' => $text,
            ]);
            $vec = $body['embedding'] ?? null;
            if (!is_array($vec) || $vec === []) {
                throw new \RuntimeException('Ollama response missing embedding');
            }
            $vectors[] = array_map('floatval', $vec);
        }
        $this->lastDims = count($vectors[0]);
        return $vectors;
    }

    /**
     * Try every configured provider in order until one answers.
     *
     * @return array<string, mixed>
     */
    private function postWithFailover(string $modelField, string $path, array $payload): array
    {
        $errors = [];

        foreach ($this->providers as $provider) {
            if ($provider['apiKey'] === '' && empty($provider['local'])) {
                continue; // unconfigured provider — skip silently
            }

            $payload['model'] = $provider[$modelField] ?? '';

            try {
                $result = $this->postTo($provider, $path, $payload);
                $this->lastProviderUsed = $provider['name'];
                return $result;
            } catch (\RuntimeException $e) {
                $errors[] = $provider['name'] . ': ' . $e->getMessage();
                \App\Syslog::error('RAG', "provider {$provider['name']} failed on {$path}: " . $e->getMessage());
            }
        }

        throw new \RuntimeException('All semantic providers failed: ' . implode(' | ', $errors));
    }

    /**
     * @param array{name: string, baseUrl: string, apiKey: string} $provider
     * @return array<string, mixed>
     */
    private function postTo(array $provider, string $path, array $payload): array
    {
        $ch = curl_init($provider['baseUrl'] . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => array_filter([
                'Content-Type: application/json',
                $provider['apiKey'] !== '' ? 'Authorization: Bearer ' . $provider['apiKey'] : null,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
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
}
