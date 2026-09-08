<?php

namespace App\Controller;

use App\ApiError;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/{version:v?1}')]
class AIController extends AbstractController
{
    #[GetMapping(path: 'ai/{id}')]
    public function ai(string $id): ResponseInterface
    {
        if (!$this->isAIEnabled()) {
            throw new ApiError(404, "AI analysis is disabled.");
        }

        $logId = new \App\Id($id);
        $log = new \App\Log($logId);

        if (!$log->exists()) {
            throw new ApiError(404, "Log not found.");
        }

        $log->renew();

        return $this->runAiAnalysis($log->getContent(), "ai:analysis:" . $logId->getRaw(), $logId->get());
    }
}
