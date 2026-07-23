<?php

namespace Handler;

class RawHandler extends \Handler
{
    public function handle(): void
    {
        try {
            $this->validateMethod('GET');
            $logId = $this->extractId('/1/raw/');
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

        $this->respondText($log->getContent(), 'text/plain');
    }
}
