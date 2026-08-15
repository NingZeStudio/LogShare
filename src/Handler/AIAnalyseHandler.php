<?php

namespace Handler;

class AIAnalyseHandler extends \Handler
{
    public function handle(): void
    {
        try {
            $this->validateMethod('POST');
        } catch (\ApiError $e) {
            $e->output();
        }

        $contentResult = $this->parseContent();
        $contentResult = $this->validateContentExists($contentResult);

        $content = is_array($contentResult) ? $contentResult['content'] : $contentResult;
        $logId = is_array($contentResult) ? ($contentResult['id'] ?? null) : null;

        if (is_string($logId) && $logId !== '') {
            if (!RequestValidator::isValidId($logId)) {
                $error = new \ApiError(400, "Invalid log id.");
                $error->output();
            }

            $id = new \Id($logId);
            $log = new \Log($id);

            if (!$log->exists()) {
                $error = new \ApiError(404, "Log not found.");
                $error->output();
            }

            $log->renew();

            if (empty($content)) {
                $content = $log->getContent();
            }

            $agentConfig = \Config::Get('ai')['agent'] ?? [];
            if ($agentConfig['enabled'] ?? false) {
                \Agent\LogAgent::analyze($content, [
                    'cacheKey' => "ai:analysis:" . $id->getRaw(),
                    'logId' => $id->get(),
                ]);
                return;
            }

            \Client\AIClient::analyzeStream($content, "ai:analysis:" . $id->getRaw());
            return;
        }

        if (empty($content)) {
            $error = new \ApiError(400, "Content is required.");
            $error->output();
        }

        $agentConfig = \Config::Get('ai')['agent'] ?? [];
        if ($agentConfig['enabled'] ?? false) {
            \Agent\LogAgent::analyze($content, [
                'cacheKey' => "ai:analysis:hash:" . hash('sha256', $content),
            ]);
            return;
        }

        \Client\AIClient::analyzeStream($content, "ai:analysis:hash:" . hash('sha256', $content));
    }
}