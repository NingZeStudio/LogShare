<?php

abstract class Handler
{
    abstract public function handle(): void;

    protected function validateMethod(string|array $methods): void
    {
        RequestValidator::validateMethod($methods);
    }

    protected function extractId(string $prefix): string
    {
        return RequestValidator::extractId($prefix);
    }

    protected function extractIds(string $prefix): array
    {
        return RequestValidator::extractIds($prefix);
    }

    protected function parseContent(): string|ApiError|array
    {
        return (new ContentParser())->getContent();
    }

    protected function validateContentExists(array|string|ApiError $result): string|array
    {
        if ($result instanceof ApiError) {
            $result->output();
        }
        return $result;
    }

    protected function respondSuccess(mixed $data, string $message = 'OK'): void
    {
        ApiResponse::success($data, $message);
    }

    protected function respondJson(mixed $data): void
    {
        ApiResponse::json($data);
    }

    protected function respondError(string $message, int $code = 400, mixed $details = null): never
    {
        ApiResponse::error($message, $code, $details);
    }

    protected function respondText(string $text, string $contentType = 'text/plain'): void
    {
        ApiResponse::text($text, $contentType);
    }
}
