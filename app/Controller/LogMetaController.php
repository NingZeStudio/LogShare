<?php

namespace App\Controller;

use App\ApiError;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/{version:v?1}')]
class LogMetaController extends AbstractController
{
    #[GetMapping(path: 'log/{id}')]
    public function meta(string $id): ResponseInterface
    {
        $logId = new \App\Id($id);
        $log = new \App\Log($logId);

        if (!$log->exists()) {
            throw new ApiError(404, "Log not found.");
        }

        return $this->respondSuccess([
            'id' => $logId->get(),
            'size' => $log->getSize(),
            'lines' => $log->getLineNumbers(),
            'created' => $log->getCreated(),
            'expires' => $log->getExpires(),
            'metadata' => array_map(fn($entry) => $entry->jsonSerialize(), $log->getMetadata()),
            'source' => $log->getSource(),
            'files' => $log->getFiles(),
            'raw' => $this->rawUrl($logId),
        ], 'Log metadata retrieved successfully');
    }

    private function rawUrl(\App\Id $id): string
    {
        $urls = \App\Config::Get('urls');
        $apiPrefix = $this->apiPrefix();
        return $urls['apiBaseUrl'] . "/{$apiPrefix}/raw/" . $id->get();
    }
}
