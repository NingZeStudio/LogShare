<?php

namespace Handler;

class LogMetaHandler extends \Handler
{
    public function handle(): void
    {
        try {
            $this->validateMethod('GET');
            $logId = Router::param('id') ?? $this->extractId(['/1/log/', '/v1/log/']);
        } catch (\ApiError $e) {
            $e->output();
        }

        $id = new \Id($logId);
        $log = new \Log($id);

        if (!$log->exists()) {
            $error = new \ApiError(404, "Log not found.");
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

    private function rawUrl(\Id $id): string
    {
        $urls = \Config::Get('urls');
        $apiPrefix = str_starts_with($_SERVER['REQUEST_URI'], '/v1/') ? 'v1' : '1';
        return $urls['apiBaseUrl'] . "/{$apiPrefix}/raw/" . $id->get();
    }
}