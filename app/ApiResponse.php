<?php

namespace App;

use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;

/**
 * API 响应助手类
 * 统一 API 响应格式，返回 PSR-7 ResponseInterface（由 Hyperf 输出）
 */
class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'Success', int $httpCode = 200): ResponseInterface
    {
        $response = new \stdClass();
        $response->success = true;
        $response->message = $message;

        if ($data !== null) {
            if (is_object($data) || is_array($data)) {
                foreach ($data as $key => $value) {
                    $response->$key = $value;
                }
            } else {
                $response->data = $data;
            }
        }

        return self::jsonResponse($response, $httpCode);
    }

    public static function error(string $message, int $httpCode = 400, mixed $errors = null): ResponseInterface
    {
        $response = new \stdClass();
        $response->success = false;
        $response->error = $message;
        $response->code = $httpCode;

        if ($errors !== null) {
            $response->errors = $errors;
        }

        return self::jsonResponse($response, $httpCode);
    }

    public static function json(mixed $data, int $httpCode = 200): ResponseInterface
    {
        return self::jsonResponse($data, $httpCode);
    }

    public static function text(string $content, string $contentType = 'text/plain', int $httpCode = 200): ResponseInterface
    {
        $response = new \Hyperf\HttpMessage\Server\Response();

        return $response
            ->withStatus($httpCode)
            ->withHeader('Content-Type', $contentType)
            ->withBody(new SwooleStream($content));
    }

    private static function jsonResponse(mixed $data, int $httpCode): ResponseInterface
    {
        $response = new \Hyperf\HttpMessage\Server\Response();

        return $response
            ->withStatus($httpCode)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(new SwooleStream(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }
}
