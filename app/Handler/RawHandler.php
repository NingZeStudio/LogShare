<?php

namespace App\Handler;

class RawHandler extends \App\Handler
{
    public function handle(): void
    {
        $this->validateMethod('GET');
        $logId = \App\Router::param('id') ?? $this->extractId(['/1/raw/', '/v1/raw/']);
        $filename = \App\Router::param('filename');

        $id = new \App\Id($logId);
        $log = new \App\Log($id);

        if (!$log->exists()) {
            $error = new \App\ApiError(404, "Log not found.");
            $error->output();
        }

        $log->renew();

        if ($filename !== null) {
            $content = $log->getFile($filename);
            if ($content === null) {
                $error = new \App\ApiError(404, "File not found.");
                $error->output();
            }
            $this->respondText($content, 'text/plain');
        }

        $this->respondText($log->getContent(), 'text/plain');
    }
}