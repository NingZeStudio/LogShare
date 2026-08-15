<?php

namespace Handler;

class RawHandler extends \Handler
{
    public function handle(): void
    {
        $this->validateMethod('GET');
        $logId = \Router::param('id') ?? $this->extractId(['/1/raw/', '/v1/raw/']);
        $filename = \Router::param('filename');

        $id = new \Id($logId);
        $log = new \Log($id);

        if (!$log->exists()) {
            $error = new \ApiError(404, "Log not found.");
            $error->output();
        }

        $log->renew();

        if ($filename !== null) {
            $content = $log->getFile($filename);
            if ($content === null) {
                $error = new \ApiError(404, "File not found.");
                $error->output();
            }
            $this->respondText($content, 'text/plain');
        }

        $this->respondText($log->getContent(), 'text/plain');
    }
}