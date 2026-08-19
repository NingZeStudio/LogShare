<?php

namespace App\Handler;

class LogMetaHandler extends \App\Handler
{
    public function handle(): void
    {
        $this->validateMethod('GET');
        $logId = \App\Router::param('id') ?? $this->extractId(['/1/log/', '/v1/log/']);

        $id = new \App\Id($logId);
        $log = new \App\Log($id);

        if (!$log->exists()) {
            $error = new \App\ApiError(404, "Log not found.");
            $error->output();
        }

        $this->respondSuccess([
            'id' => $id->get(),
            'size' => $log->getSize(),
            'lines' => $log->getLineNumbers(),
            'created' => $log->getCreated(),
            'expires' => $log->getExpires(),
            'metadata' => array_map(fn($entry) => $entry->jsonSerialize(), $log->getMetadata()),
            'source' => $log->getSource(),
            'files' => $log->getFiles(),
            'raw' => $this->rawUrl($id),
        ], 'Log metadata retrieved successfully');
    }

    private function rawUrl(\App\Id $id): string
    {
        $urls = \App\Config::Get('urls');
        $apiPrefix = $this->apiPrefix();
        return $urls['apiBaseUrl'] . "/{$apiPrefix}/raw/" . $id->get();
    }
}