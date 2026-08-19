<?php

namespace App\Handler;

class InsightsHandler extends \App\Handler
{
    public function handle(): void
    {
        $this->validateMethod('GET');
        $logId = $this->extractId(['/1/insights/', '/v1/insights/']);

        $id = new \App\Id($logId);
        $log = new \App\Log($id);

        if (!$log->exists()) {
            $error = new \App\ApiError(404, "Log not found.");
            $error->output();
        }

        $log->renew();

        $codexLog = $log->get();
        $codexLog->setIncludeEntries(false);

        $this->respondJson($codexLog);
    }
}
