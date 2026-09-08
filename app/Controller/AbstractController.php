<?php

namespace App\Controller;

use App\ApiError;
use App\ApiResponse;
use App\ContentParser;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Response;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

abstract class AbstractController
{
    #[Inject]
    protected RequestInterface $request;

    #[Inject]
    protected Response $response;

    protected function parseContent(): string|ApiError|array
    {
        return (new ContentParser($this->request))->getContent();
    }

    protected function validateContentExists(array|string|ApiError $result): string|array
    {
        if ($result instanceof ApiError) {
            throw $result;
        }
        return $result;
    }

    protected function apiPrefix(): string
    {
        $path = $this->request->getUri()->getPath();
        return str_starts_with($path, '/1/') ? '1' : 'v1';
    }

    protected function authorizationHeader(): ?string
    {
        $header = $this->request->getHeaderLine('Authorization');
        return $header !== '' ? $header : null;
    }

    /**
     * Whether the AI analysis feature is enabled (ai.enabled, default on).
     */
    protected function isAIEnabled(): bool
    {
        return (bool) (\App\Config::Get('ai')['enabled'] ?? true);
    }

    /**
     * AI 分析统一分发（AIAnalyseController / AIController 共用）。
     *
     * ai.queue.enabled 时全量走 Redis Streams 微队列：入队 → SSE 中继 job
     * 事件流；队列满直接 429 + Retry-After；缓存已命中则不入队直接 inline
     * 回放；Redis 故障按 failOpen 回退 inline（与队列关闭时的现状路径逐字节一致）。
     */
    protected function runAiAnalysis(string $content, string $cacheKey, ?string $logId = null): PsrResponseInterface
    {
        $aiConfig = \App\Config::Get('ai');
        $agent = (bool) ($aiConfig['agent']['enabled'] ?? false);
        $queueCfg = \App\Ai\AnalysisQueue::config();

        if ($queueCfg['enabled'] === true && !$this->aiCacheHit($cacheKey)) {
            $job = null;
            try {
                if (\App\Ai\AnalysisQueue::queueDepth() >= (int) $queueCfg['maxQueue']) {
                    return $this->respondError('AI 分析队列已满，请稍后重试。', 429)
                        ->withHeader('Retry-After', '30');
                }
                $job = \App\Ai\AnalysisQueue::enqueue($content, $cacheKey, $logId, 1800);
            } catch (\Throwable $e) {
                \App\Syslog::error('AiQueue', 'enqueue failed: ' . $e->getMessage());
                if ($queueCfg['failOpen'] !== true) {
                    throw new ApiError(503, 'AI analysis queue is unavailable, please retry later.');
                }
                // fail-open：$job 保持 null，落到下方 inline 路径
            }
            if ($job !== null) {
                \App\Ai\AnalysisQueue::relay($job['jobId'], $this->response);
                return $this->response;
            }
        }

        if ($agent) {
            \App\Agent\LogAgent::analyze($content, [
                'cacheKey' => $cacheKey,
                'logId' => $logId,
            ], $this->response);
        } else {
            \App\Client\AIClient::analyzeStream($content, $cacheKey, 1800, $this->response);
        }
        return $this->response;
    }

    /**
     * 队列预检：缓存已命中就不必占用队列槽位（Redis 故障按未命中处理）。
     */
    private function aiCacheHit(string $cacheKey): bool
    {
        try {
            $cached = \App\Cache\RedisCache::Get(\App\Ai\AnalysisQueue::cacheKeyFor($cacheKey));
            return $cached !== null && $cached !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function respondSuccess(mixed $data, string $message = 'OK'): PsrResponseInterface
    {
        return ApiResponse::success($data, $message);
    }

    protected function respondJson(mixed $data): PsrResponseInterface
    {
        return ApiResponse::json($data);
    }

    protected function respondRawJson(string $json): PsrResponseInterface
    {
        return ApiResponse::jsonRaw($json);
    }

    protected function respondError(string $message, int $code = 400, mixed $details = null): PsrResponseInterface
    {
        return ApiResponse::error($message, $code, $details);
    }

    protected function respondText(string $text, string $contentType = 'text/plain'): PsrResponseInterface
    {
        return ApiResponse::text($text, $contentType);
    }
}
