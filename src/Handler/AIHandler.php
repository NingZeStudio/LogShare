<?php

namespace Handler;

class AIHandler extends \Handler
{
    public function handle(): void
    {
        $this->validateMethod('GET');
        $logId = $this->extractId(['/1/ai/', '/v1/ai/']);

        $id = new \Id($logId);
        $log = new \Log($id);

        if (!$log->exists()) {
            $error = new \ApiError(404, "Log not found.");
            $error->output();
        }

        $log->renew();

        $agentConfig = \Config::Get('ai')['agent'] ?? [];
        if ($agentConfig['enabled'] ?? false) {
            \Agent\LogAgent::analyze($log->getContent(), [
                'cacheKey' => "ai:analysis:" . $id->getRaw(),
                'logId' => $id->get(),
            ]);
            return;
        }

        \Client\AIClient::analyzeStream($log->getContent(), "ai:analysis:" . $id->getRaw());
    }
}
