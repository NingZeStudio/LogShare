<?php

namespace App\Handler;

class AIAnalyseHandler extends \App\Handler
{
    public function handle(): void
    {
        $this->validateMethod('POST');

        $contentResult = $this->parseContent();
        $contentResult = $this->validateContentExists($contentResult);

        $content = is_array($contentResult) ? $contentResult['content'] : $contentResult;
        $logId = is_array($contentResult) ? ($contentResult['id'] ?? null) : null;

        if (is_string($logId) && $logId !== '') {
            if (!\App\RequestValidator::isValidId($logId)) {
                $error = new \App\ApiError(400, "Invalid log id.");
                $error->output();
            }

            $id = new \App\Id($logId);
            $log = new \App\Log($id);

            if (!$log->exists()) {
                $error = new \App\ApiError(404, "Log not found.");
                $error->output();
            }

            $log->renew();

            if (empty($content)) {
                $content = $log->getContent();
            }

            $agentConfig = \App\Config::Get('ai')['agent'] ?? [];
            if ($agentConfig['enabled'] ?? false) {
                \App\Agent\LogAgent::analyze($content, [
                    'cacheKey' => "ai:analysis:" . $id->getRaw(),
                    'logId' => $id->get(),
                ]);
                return;
            }

            \App\Client\AIClient::analyzeStream($content, "ai:analysis:" . $id->getRaw());
            return;
        }

        if (empty($content)) {
            $error = new \App\ApiError(400, "Content is required.");
            $error->output();
        }

        $agentConfig = \App\Config::Get('ai')['agent'] ?? [];
        if ($agentConfig['enabled'] ?? false) {
            \App\Agent\LogAgent::analyze($content, [
                'cacheKey' => "ai:analysis:hash:" . hash('sha256', $content),
            ]);
            return;
        }

        \App\Client\AIClient::analyzeStream($content, "ai:analysis:hash:" . hash('sha256', $content));
    }
}