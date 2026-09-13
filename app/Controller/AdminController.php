<?php

declare(strict_types=1);

namespace App\Controller;

use App\ApiError;
use App\Config;
use App\Id;
use App\Log;
use App\Middleware\AdminAuthMiddleware;
use App\Rag\RagManager;
use App\Storage\StorageInterface;
use App\Version;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;
use Psr\Http\Message\ResponseInterface;

/**
 * Controller for Admin operations under /{version:v?1}/admin.
 *
 * Protected by AdminAuthMiddleware (requires valid admin Bearer token).
 */
#[Controller(prefix: '/{version:v?1}/admin')]
#[Middleware(AdminAuthMiddleware::class)]
class AdminController extends AbstractController
{
    /**
     * @return class-string<StorageInterface>
     */
    private function getStorageClass(): string
    {
        $storageConfig = Config::Get('storage');
        $storageId = $storageConfig['storageId'] ?? 's';
        $storages = $storageConfig['storages'] ?? [];
        if (!isset($storages[$storageId]['class'])) {
            throw new ApiError(500, "Primary storage [{$storageId}] is not configured");
        }
        /** @var class-string<StorageInterface> $storageClass */
        $storageClass = $storages[$storageId]['class'];
        return $storageClass;
    }

    #[GetMapping(path: 'logs')]
    public function listLogs(): ResponseInterface
    {
        $params = $this->request->getQueryParams();

        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $source = isset($params['source']) && $params['source'] !== '' ? (string) $params['source'] : null;
        $since = isset($params['since']) && ctype_digit((string) $params['since']) ? (int) $params['since'] : null;
        $until = isset($params['until']) && ctype_digit((string) $params['until']) ? (int) $params['until'] : null;
        $keyword = isset($params['keyword']) && trim((string) $params['keyword']) !== '' ? trim((string) $params['keyword']) : null;

        $storage = $this->getStorageClass();

        $items = $storage::List($limit, $offset, $source, $since, $until, $keyword);
        $total = $storage::Count($source, $since, $until, $keyword);

        return $this->respondSuccess([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => (int) ceil($total / $limit),
        ], 'Logs retrieved successfully');
    }

    #[GetMapping(path: 'logs/{id}')]
    public function getLog(string $id): ResponseInterface
    {
        $logId = trim($id);
        if ($logId === '') {
            throw new ApiError(400, 'Log ID is required');
        }

        try {
            $parsedId = new Id($logId);
        } catch (\Throwable) {
            throw new ApiError(400, "Invalid log ID format: {$logId}");
        }

        $log = new Log($parsedId);
        if (!$log->exists()) {
            throw new ApiError(404, "Log not found: {$logId}");
        }

        return $this->respondSuccess([
            'id' => $log->getId()?->get() ?? $logId,
            'size' => $log->getSize(),
            'lines' => $log->getLineNumbers(),
            'created' => $log->getCreated(),
            'expires' => $log->getExpires(),
            'source' => $log->getSource(),
            'files' => $log->getFiles(),
            'metadata' => $log->getMetadata(),
            'content' => $log->getContent(),
        ], 'Log details retrieved successfully');
    }

    #[DeleteMapping(path: 'logs/{id}')]
    public function deleteLogs(string $id): ResponseInterface
    {
        $logIds = array_values(array_filter(array_map('trim', explode(',', $id)), fn($i) => $i !== ''));
        if (empty($logIds)) {
            throw new ApiError(400, 'At least one valid ID is required');
        }

        $results = [
            'deleted' => [],
            'failed' => [],
        ];

        foreach ($logIds as $logId) {
            try {
                $parsedId = new Id($logId);
                $log = new Log($parsedId);
            } catch (\Throwable $e) {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Invalid log ID: {$e->getMessage()}",
                    'code' => 400,
                ];
                continue;
            }

            if (!$log->exists()) {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Log not found: {$logId}",
                    'code' => 404,
                ];
                continue;
            }

            if ($log->delete()) {
                $results['deleted'][] = $logId;
            } else {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Failed to delete log: {$logId}",
                    'code' => 500,
                ];
            }
        }

        if (empty($results['deleted'])) {
            $errorMessages = array_column($results['failed'], 'message');
            return $this->respondError('Failed to delete logs: ' . implode('; ', $errorMessages), 400, $results['failed']);
        }

        return $this->respondSuccess([
            'deleted' => $results['deleted'],
            'failed' => $results['failed'],
            'total' => count($logIds),
            'deletedCount' => count($results['deleted']),
            'failedCount' => count($results['failed']),
        ], 'Log deletion completed');
    }

    #[GetMapping(path: 'system/queue')]
    public function getQueueStatus(): ResponseInterface
    {
        $queueCfg = \App\Ai\AnalysisQueue::config();
        $depth = 0;
        try {
            $depth = \App\Ai\AnalysisQueue::queueDepth();
        } catch (\Throwable) {
            // Redis may be down or unconfigured
        }

        return $this->respondSuccess([
            'enabled' => $queueCfg['enabled'],
            'depth' => $depth,
            'maxQueue' => (int) $queueCfg['maxQueue'],
            'maxConcurrent' => (int) $queueCfg['maxConcurrent'],
            'waitTimeout' => (int) $queueCfg['waitTimeout'],
            'claimIdleMs' => (int) $queueCfg['claimIdleMs'],
            'jobTtl' => (int) $queueCfg['jobTtl'],
            'failOpen' => (bool) $queueCfg['failOpen'],
        ], 'Queue status retrieved successfully');
    }

    #[GetMapping(path: 'system/stats')]
    public function getSystemStats(): ResponseInterface
    {
        $storageConfig = Config::Get('storage');
        $storageId = $storageConfig['storageId'] ?? 's';
        $storage = $this->getStorageClass();

        $totalLogs = 0;
        try {
            $totalLogs = $storage::Count();
        } catch (\Throwable) {
            // Database might be temporarily unavailable
        }

        $ragDbPath = \App\Rag\RagSearch::resolveDbPath();
        $ragIndexMtime = file_exists($ragDbPath) ? filemtime($ragDbPath) : null;

        return $this->respondSuccess([
            'version' => Version::VERSION,
            'phpVersion' => PHP_VERSION,
            'swooleVersion' => phpversion('swoole') ?: null,
            'storageBackend' => $storageId,
            'totalLogs' => $totalLogs,
            'storageTime' => (int) ($storageConfig['storageTime'] ?? 0),
            'aiEnabled' => (bool) (Config::Get('ai')['enabled'] ?? false),
            'ragIndexUpdated' => $ragIndexMtime,
        ], 'System statistics retrieved successfully');
    }

    #[GetMapping(path: 'config')]
    public function getConfig(): ResponseInterface
    {
        return $this->respondSuccess(Config::getMasked(), 'Configuration retrieved successfully');
    }

    #[PutMapping(path: 'config')]
    public function updateConfig(): ResponseInterface
    {
        $payload = $this->request->getParsedBody();
        if (!is_array($payload) || empty($payload)) {
            throw new ApiError(400, 'Invalid or empty configuration payload');
        }

        try {
            Config::saveDynamic($payload);
        } catch (\InvalidArgumentException $e) {
            throw new ApiError(422, 'Configuration validation failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            throw new ApiError(500, 'Failed to save dynamic configuration: ' . $e->getMessage());
        }

        return $this->respondSuccess(Config::getMasked(), 'Configuration updated successfully');
    }

    #[PostMapping(path: 'config/reset')]
    public function resetConfig(): ResponseInterface
    {
        try {
            Config::resetDynamic();
        } catch (\Throwable $e) {
            throw new ApiError(500, 'Failed to reset configuration: ' . $e->getMessage());
        }

        return $this->respondSuccess(Config::getMasked(), 'Configuration reset to defaults successfully');
    }

    #[PostMapping(path: 'config/test-ai')]
    public function testAiConnection(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $rawConfig = Config::all();
        $aiConfig = $rawConfig['ai'] ?? [];

        $baseUrl = trim((string) ($body['baseUrl'] ?? ($aiConfig['baseUrl'] ?? '')));
        $model = trim((string) ($body['model'] ?? ($aiConfig['model'] ?? '')));
        $apiKey = trim((string) ($body['apiKey'] ?? ''));

        if ($apiKey === '' || str_contains($apiKey, '****') || $apiKey === '********') {
            $apiKeys = $aiConfig['apiKeys'] ?? [];
            $apiKey = (string) ($apiKeys[0] ?? '');
        }

        $timeout = min(60, max(2, (int) ($body['timeout'] ?? ($aiConfig['timeout'] ?? 15))));

        if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new ApiError(400, 'A valid baseUrl is required for AI connection test');
        }
        if ($model === '') {
            throw new ApiError(400, 'Model is required for AI connection test');
        }
        if ($apiKey === '') {
            throw new ApiError(400, 'API key is required for AI connection test');
        }

        $endpoint = rtrim($baseUrl, '/') . '/chat/completions';
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => 'ping'],
            ],
            'max_tokens' => 5,
        ];

        $start = microtime(true);
        $ch = curl_init();
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_FORBID_REUSE => true,
            ]);

            $response = curl_exec($ch);
            $latencyMs = (int) round((microtime(true) - $start) * 1000);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($curlError !== '') {
                return $this->respondSuccess([
                    'success' => false,
                    'latencyMs' => $latencyMs,
                    'httpCode' => $httpCode,
                    'error' => 'cURL error: ' . $curlError,
                ], 'AI connection test failed');
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                return $this->respondSuccess([
                    'success' => true,
                    'latencyMs' => $latencyMs,
                    'httpCode' => $httpCode,
                    'model' => $model,
                ], 'AI connection test succeeded');
            }

            $decoded = is_string($response) ? json_decode($response, true) : null;
            $msg = is_array($decoded) && isset($decoded['error']['message'])
                ? (string) $decoded['error']['message']
                : (is_string($response) ? substr($response, 0, 200) : 'HTTP ' . $httpCode);

            return $this->respondSuccess([
                'success' => false,
                'latencyMs' => $latencyMs,
                'httpCode' => $httpCode,
                'error' => $msg,
            ], 'AI connection returned non-2xx status');
        } finally {
            if ($ch instanceof \CurlHandle) {
                curl_close($ch);
            }
            $ch = null;
        }
    }

    #[PostMapping(path: 'config/test-rag-provider')]
    public function testRagProvider(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $rawConfig = Config::all();
        $providers = $rawConfig['ai']['rag']['providers'] ?? [];

        $baseUrl = trim((string) ($body['baseUrl'] ?? ''));
        $apiKey = trim((string) ($body['apiKey'] ?? ''));
        $embeddingModel = trim((string) ($body['embeddingModel'] ?? ''));
        $providerName = trim((string) ($body['name'] ?? ''));

        if ($apiKey === '' || str_contains($apiKey, '****') || $apiKey === '********') {
            if (is_array($providers)) {
                foreach ($providers as $p) {
                    if (is_array($p) && (($providerName !== '' && ($p['name'] ?? '') === $providerName) || ($baseUrl !== '' && ($p['baseUrl'] ?? '') === $baseUrl))) {
                        $apiKey = (string) ($p['apiKey'] ?? '');
                        if ($embeddingModel === '') {
                            $embeddingModel = (string) ($p['embeddingModel'] ?? '');
                        }
                        break;
                    }
                }
            }
        }

        if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new ApiError(400, 'A valid baseUrl is required for RAG provider test');
        }
        if ($embeddingModel === '') {
            throw new ApiError(400, 'embeddingModel is required for RAG provider test');
        }
        if ($apiKey === '') {
            throw new ApiError(400, 'API key is required for RAG provider test');
        }

        $endpoint = rtrim($baseUrl, '/') . '/embeddings';
        $payload = [
            'model' => $embeddingModel,
            'input' => ['Hello world test embedding'],
        ];

        $start = microtime(true);
        $ch = curl_init();
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_FORBID_REUSE => true,
            ]);

            $response = curl_exec($ch);
            $latencyMs = (int) round((microtime(true) - $start) * 1000);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($curlError !== '') {
                return $this->respondSuccess([
                    'success' => false,
                    'latencyMs' => $latencyMs,
                    'httpCode' => $httpCode,
                    'error' => 'cURL error: ' . $curlError,
                ], 'Embedding provider test failed');
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                $decoded = is_string($response) ? json_decode($response, true) : null;
                $dim = isset($decoded['data'][0]['embedding']) && is_array($decoded['data'][0]['embedding'])
                    ? count($decoded['data'][0]['embedding'])
                    : null;

                return $this->respondSuccess([
                    'success' => true,
                    'latencyMs' => $latencyMs,
                    'httpCode' => $httpCode,
                    'dimension' => $dim,
                    'model' => $embeddingModel,
                ], 'Embedding provider responded successfully');
            }

            $decoded = is_string($response) ? json_decode($response, true) : null;
            $msg = is_array($decoded) && isset($decoded['error']['message'])
                ? (string) $decoded['error']['message']
                : (is_string($response) ? substr($response, 0, 200) : 'HTTP ' . $httpCode);

            return $this->respondSuccess([
                'success' => false,
                'latencyMs' => $latencyMs,
                'httpCode' => $httpCode,
                'error' => $msg,
            ], 'Embedding provider returned non-2xx status');
        } finally {
            if ($ch instanceof \CurlHandle) {
                curl_close($ch);
            }
            $ch = null;
        }
    }

    #[GetMapping(path: 'rag/stats')]
    public function getRagStats(): ResponseInterface
    {
        return $this->respondSuccess(RagManager::getStats(), 'RAG statistics retrieved successfully');
    }

    #[PostMapping(path: 'rag/build')]
    public function triggerRagBuild(): ResponseInterface
    {
        $result = RagManager::triggerBuild();
        return $this->respondSuccess($result, $result['message']);
    }

    #[GetMapping(path: 'rag/build/status')]
    public function getRagBuildStatus(): ResponseInterface
    {
        return $this->respondSuccess(RagManager::getBuildStatus(), 'RAG build status retrieved successfully');
    }

    #[PostMapping(path: 'rag/search')]
    public function searchRag(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $query = trim((string) ($body['query'] ?? ''));
        $limit = min(20, max(1, (int) ($body['limit'] ?? 5)));

        if ($query === '') {
            throw new ApiError(400, 'Query parameter is required for RAG search');
        }

        $results = RagManager::search($query, $limit);
        return $this->respondSuccess([
            'query' => $query,
            'count' => count($results),
            'items' => $results,
        ], 'RAG search executed successfully');
    }
}

