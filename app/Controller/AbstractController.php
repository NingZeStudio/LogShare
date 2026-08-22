<?php

namespace App\Controller;

use App\ApiError;
use App\ApiResponse;
use App\ContentParser;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Response;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

abstract class AbstractController
{
    #[Inject]
    protected RequestInterface $request;

    #[Inject]
    protected Response $response;

    protected function parseContent(): string|ApiError|array
    {
        return (new ContentParser($this->request))->getContent();
    }

    protected function validateContentExists(array|string|ApiError $result): string|array
    {
        if ($result instanceof ApiError) {
            throw $result;
        }
        return $result;
    }

    protected function apiPrefix(): string
    {
        $path = $this->request->getUri()->getPath();
        return str_starts_with($path, '/1/') ? '1' : 'v1';
    }

    protected function authorizationHeader(): ?string
    {
        $header = $this->request->getHeaderLine('Authorization');
        return $header !== '' ? $header : null;
    }

    /**
     * Whether the AI analysis feature is enabled (ai.enabled, default on).
     */
    protected function isAIEnabled(): bool
    {
        return (bool) (\App\Config::Get('ai')['enabled'] ?? true);
    }

    protected function respondSuccess(mixed $data, string $message = 'OK'): PsrResponseInterface
    {
        return ApiResponse::success($data, $message);
    }

    protected function respondJson(mixed $data): PsrResponseInterface
    {
        return ApiResponse::json($data);
    }

    protected function respondError(string $message, int $code = 400, mixed $details = null): PsrResponseInterface
    {
        return ApiResponse::error($message, $code, $details);
    }

    protected function respondText(string $text, string $contentType = 'text/plain'): PsrResponseInterface
    {
        return ApiResponse::text($text, $contentType);
    }
}
