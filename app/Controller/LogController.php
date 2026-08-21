<?php

namespace App\Controller;

use App\ApiError;
use App\Data\Token;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/{version:v?1}')]
class LogController extends AbstractController
{
    #[PostMapping(path: 'log')]
    public function create(): ResponseInterface
    {
        $content = $this->validateContentExists($this->parseContent());

        $metadata = [];
        $source = null;
        $files = null;
        if (is_array($content)) {
            $metadata = $content['metadata'] ?? [];
            $source = $content['source'] ?? null;
            $files = !empty($content['files']) ? $content['files'] : null;
            $content = $content['content'];

            if (empty($content) && $files !== null) {
                $content = $files[0]['data'] ?? '';
            }
        }

        $log = new \App\Log();
        $token = new Token();

        $id = $log->put($content, $token, $metadata, $source, $files);

        $urls = \App\Config::Get('urls');
        $apiPrefix = $this->apiPrefix();

        return $this->respondSuccess([
            'id' => $id->get(),
            'url' => $urls['baseUrl'] . "/" . $id->get(),
            'raw' => $urls['apiBaseUrl'] . "/{$apiPrefix}/raw/" . $id->get(),
            'token' => $token->get(),
        ], 'Log submitted successfully');
    }

    #[DeleteMapping(path: 'log/{id}')]
    public function delete(string $id): ResponseInterface
    {
        $logIds = array_values(array_filter(array_map('trim', explode(',', $id)), fn($i) => $i !== ''));

        if (empty($logIds)) {
            throw new ApiError(400, "At least one valid ID is required");
        }

        $authorizationHeader = $this->authorizationHeader();
        $requestToken = null;
        if ($authorizationHeader && str_starts_with($authorizationHeader, 'Bearer ')) {
            $requestToken = substr($authorizationHeader, 7);
        }

        if (!$requestToken) {
            throw new ApiError(401, "Missing token in Authorization header. Use: Bearer <token>");
        }

        $results = [
            'deleted' => [],
            'failed' => [],
        ];

        foreach ($logIds as $logId) {
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $logId)) {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Invalid ID format: {$logId}",
                    'code' => 400,
                ];
                continue;
            }

            $logIdObj = new \App\Id($logId);
            $log = new \App\Log($logIdObj);

            if (!$log->exists()) {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Log not found: {$logId}",
                    'code' => 404,
                ];
                continue;
            }

            if (!$log->verifyToken($requestToken)) {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Invalid token for log: {$logId}",
                    'code' => 403,
                ];
                continue;
            }

            if ($log->delete()) {
                $results['deleted'][] = $logId;
            } else {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Failed to delete log: {$logId}",
                    'code' => 500,
                ];
            }
        }

        if (empty($results['deleted'])) {
            $errorMessages = array_column($results['failed'], 'message');
            return $this->respondError("Failed to delete logs: " . implode('; ', $errorMessages), 400, $results['failed']);
        }

        return $this->respondSuccess([
            'deleted' => $results['deleted'],
            'failed' => $results['failed'],
            'total' => count($logIds),
            'deletedCount' => count($results['deleted']),
            'failedCount' => count($results['failed']),
        ], 'Log deletion completed');
    }
}
