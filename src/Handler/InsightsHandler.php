<?php

namespace Handler;

class InsightsHandler extends \Handler
{
    public function handle(): void
    {
        $this->validateMethod('GET');
        $logId = $this->extractId(['/1/insights/', '/v1/insights/']);

        $id = new \Id($logId);
        $log = new \Log($id);

        if (!$log->exists()) {
            $error = new \ApiError(404, "Log not found.");
            $error->output();
        }

        $log->renew();

        $codexLog = $log->get();
        $codexLog->setIncludeEntries(false);

        $this->respondJson($codexLog);
    }
}
