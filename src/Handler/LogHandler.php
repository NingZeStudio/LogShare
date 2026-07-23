<?php

namespace Handler;

use Data\Token;

class LogHandler extends \Handler
{
    public function handle(): void
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->handleCreate();
            } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
                $this->handleDelete();
            } else {
                throw new \ApiError(405, "Method not allowed. Use POST to create or DELETE to delete.");
            }
        } catch (\ApiError $e) {
            $e->output();
        }
    }

    private function handleCreate(): void
    {
        $content = $this->parseContent();
        $content = $this->validateContentExists($content);

        $metadata = [];
        $source = null;
        if (is_array($content)) {
            $metadata = $content['metadata'] ?? [];
            $source = $content['source'] ?? null;
            $content = $content['content'];
        }

        $log = new \Log();
        $token = new Token();

        try {
            $id = $log->put($content, $token, $metadata, $source);
        } catch (\Exception $e) {
            $error = new \ApiError(400, $e->getMessage());
            $error->output();
        }

        $urls = \Config::Get('urls');

        $this->respondSuccess([
            'id' => $id->get(),
            'url' => $urls['baseUrl'] . "/" . $id->get(),
            'raw' => $urls['apiBaseUrl'] . "/1/raw/" . $id->get(),
            'token' => $token->get()
        ], 'Log submitted successfully');
    }

    private function handleDelete(): void
    {
        $logIds = $this->extractIds('/1/log/');

        $authorizationHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
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
