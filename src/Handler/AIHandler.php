<?php

namespace Handler;

class AIHandler extends \Handler
{
    public function handle(): void
    {
        try {
            $this->validateMethod('GET');
            $logId = $this->extractId('/1/ai/');
        } catch (\ApiError $e) {
            $e->output();
        }

        $id = new \Id($logId);
        $log = new \Log($id);

        if (!$log->exists()) {
            $error = new \ApiError(404, "Log not found.");
            $error->output();
        }

        $log->renew();

        \Client\AIClient::analyzeStream($log->getContent(), "ai:analysis:" . $id->getRaw());
    }
}
