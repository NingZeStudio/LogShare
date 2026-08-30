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
 */
final class SemanticClient
{
    private const CONNECT_TIMEOUT = 10;
    private const MAX_RESPONSE_BYTES = 2097152;

    /**
     * @param array<int, array{name: string, baseUrl: string, apiKey: string, embeddingModel: string}> $providers
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
        ];
    }

    /**
     * Whether semantic enhancement can actually run (at least one keyed provider).
     */
    public function isConfigured(): bool
    {
        foreach ($this->providers as $p) {
            if ($p['apiKey'] !== '') {
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

    /**
     * One-line summary of the keyed providers and their embedding models,
     * e.g. "siliconflow/BAAI/bge-m3 -> huidev/bge-m3".
     */
    public function describe(): string
    {
        $parts = [];
        foreach ($this->providers as $p) {
            if ($p['apiKey'] === '') {
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

        return array_values($vectors);
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
            if ($provider['apiKey'] === '') {
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
            CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $provider['apiKey'],
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
        ]);

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
    }
}
