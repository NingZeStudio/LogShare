<?php

namespace Handler;

use Data\Token;

class LogHandler extends \Handler
{
    public function handle(): void
    {
        $this->validateMethod(['POST', 'DELETE']);

        if ($this->requestMethod() === 'POST') {
            $this->handleCreate();
        } elseif ($this->requestMethod() === 'DELETE') {
            $this->handleDelete();
        }
    }

    private function handleCreate(): void
    {
        $content = $this->parseContent();
        $content = $this->validateContentExists($content);

        $metadata = [];
        $source = null;
        $files = null;
        if (is_array($content)) {
            $metadata = $content['metadata'] ?? [];
            $source = $content['source'] ?? null;
            $files = !empty($content['files']) ? $content['files'] : null;
            $content = $content['content'];

            // Multi-file upload: fall back to the first file as the primary content
            if (empty($content) && $files !== null) {
                $content = $files[0]['data'] ?? '';
            }
        }

        $log = new \Log();
        $token = new Token();

        try {
            $id = $log->put($content, $token, $metadata, $source, $files);
        } catch (\Exception $e) {
            $error = new \ApiError(400, $e->getMessage());
            $error->output();
        }

        $urls = \Config::Get('urls');

        $apiPrefix = $this->apiPrefix();

        $this->respondSuccess([
            'id' => $id->get(),
            'url' => $urls['baseUrl'] . "/" . $id->get(),
            'raw' => $urls['apiBaseUrl'] . "/{$apiPrefix}/raw/" . $id->get(),
            'token' => $token->get()
        ], 'Log submitted successfully');
    }

    private function handleDelete(): void
    {
        $logIds = $this->extractIds(['/1/log/', '/v1/log/']);

        $authorizationHeader = $this->authorizationHeader();
        $requestToken = null;
        if ($authorizationHeader && str_starts_with($authorizationHeader, 'Bearer ')) {
            $requestToken = substr($authorizationHeader, 7);
        }

        if (!$requestToken) {
            throw new \ApiError(401, "Missing token in Authorization header. Use: Bearer <token>");
        }

        $results = [
            'deleted' => [],
            'failed' => []
        ];

        foreach ($logIds as $logId) {
            $id = new \Id($logId);
            $log = new \Log($id);

            if (!$log->exists()) {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Log not found: {$logId}",
                    'code' => 404
                ];
                continue;
            }

            if (!$log->verifyToken($requestToken)) {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Invalid token for log: {$logId}",
                    'code' => 403
                ];
                continue;
            }

            if ($log->delete()) {
                $results['deleted'][] = $logId;
            } else {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Failed to delete log: {$logId}",
                    'code' => 500
                ];
            }
        }

        if (empty($results['deleted'])) {
            $errorMessages = array_column($results['failed'], 'message');
            $this->respondError("Failed to delete logs: " . implode('; ', $errorMessages), 400, $results['failed']);
        }

        $this->respondSuccess([
            'deleted' => $results['deleted'],
            'failed' => $results['failed'],
            'total' => count($logIds),
            'deletedCount' => count($results['deleted']),
            'failedCount' => count($results['failed'])
        ], 'Log deletion completed');
    }
}
