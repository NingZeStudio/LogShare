<?php

namespace App\Handler;

class AIHandler extends \App\Handler
{
    public function handle(): void
    {
        $this->validateMethod('GET');
        $logId = $this->extractId(['/1/ai/', '/v1/ai/']);

        $id = new \App\Id($logId);
        $log = new \App\Log($id);

        if (!$log->exists()) {
            $error = new \App\ApiError(404, "Log not found.");
            $error->output();
        }

        $log->renew();

        $agentConfig = \App\Config::Get('ai')['agent'] ?? [];
        if ($agentConfig['enabled'] ?? false) {
            \App\Agent\LogAgent::analyze($log->getContent(), [
                'cacheKey' => "ai:analysis:" . $id->getRaw(),
                'logId' => $id->get(),
            ]);
            return;
        }

        \App\Client\AIClient::analyzeStream($log->getContent(), "ai:analysis:" . $id->getRaw());
    }
}
