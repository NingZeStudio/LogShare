<?php

namespace App\Controller;

use App\ApiError;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/{version:v?1}')]
class AIAnalyseController extends AbstractController
{
    #[PostMapping(path: 'ai/analyse')]
    public function analyse(): ResponseInterface
    {
        if (!$this->isAIEnabled()) {
            throw new ApiError(404, "AI analysis is disabled.");
        }

        $contentResult = $this->validateContentExists($this->parseContent());

        $content = is_array($contentResult) ? $contentResult['content'] : $contentResult;
        $logId = is_array($contentResult) ? ($contentResult['id'] ?? null) : null;

        if (is_string($logId) && $logId !== '') {
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $logId)) {
                throw new ApiError(400, "Invalid log id.");
            }

            $id = new \App\Id($logId);
            $log = new \App\Log($id);

            if (!$log->exists()) {
                throw new ApiError(404, "Log not found.");
            }

            $log->renew();

            if (empty($content)) {
                $content = $log->getContent();
            }

            return $this->runAnalysis($content, "ai:analysis:" . $id->getRaw());
        }

        if (empty($content)) {
            throw new ApiError(400, "Content is required.");
        }

        return $this->runAnalysis($content, "ai:analysis:hash:" . hash('sha256', $content));
    }

    private function runAnalysis(string $content, string $cacheKey): ResponseInterface
    {
        $agentConfig = \App\Config::Get('ai')['agent'] ?? [];
        if ($agentConfig['enabled'] ?? false) {
            \App\Agent\LogAgent::analyze($content, [
                'cacheKey' => $cacheKey,
            ], $this->response);
            return $this->response;
        }

        \App\Client\AIClient::analyzeStream($content, $cacheKey, 1800, $this->response);
        return $this->response;
    }
}
