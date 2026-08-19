<?php

namespace App;

/**
 * API 请求验证助手类
 * 用于统一处理请求验证和 ID 解析
 */
class RequestValidator
{
    /**
     * Get the current HTTP request method.
     *
     * @return string
     */
    public static function getMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * Get the current request URI.
     *
     * @return string
     */
    public static function getRequestUri(): string
    {
        return $_SERVER['REQUEST_URI'] ?? '';
    }

    /**
     * Get the value of the Authorization header, if present.
     *
     * @return string|null
     */
    public static function getAuthorizationHeader(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        return is_string($header) ? $header : null;
    }

    /**
     * Check whether the current request uses the /v1/ prefix.
     *
     * @return bool
     */
    public static function isV1Request(): bool
    {
        return str_starts_with(self::getRequestUri(), '/v1/');
    }

    /**
     * 验证 HTTP 请求方法
     *
     * @param string|array $allowedMethods 允许的请求方法
     * @return void
     * @throws ApiError
     */
    public static function validateMethod(string|array $allowedMethods): void
    {
        $allowedMethods = is_array($allowedMethods) ? $allowedMethods : [$allowedMethods];
        $allowedMethods = array_map('strtoupper', $allowedMethods);

        if (!in_array($_SERVER['REQUEST_METHOD'], $allowedMethods)) {
            throw new ApiError(
                405,
                "Method not allowed. Only " . implode('/', $allowedMethods) . " requests are allowed for this endpoint."
            );
        }
    }

    /**
     * 验证 ID 格式
     *
     * @param string $id
     * @return bool
     */
    public static function isValidId(string $id): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]+$/', $id);
    }

    /**
     * 从 URL 路径提取 ID 参数
     * 支持单 ID 和多 ID（逗号分隔）
     *
     * @param string|array $prefix URL 前缀，例如 "/1/raw/" 或 ["/1/raw/", "/v1/raw/"]
     * @return array ID 列表
     * @throws ApiError
     */
    public static function extractIds(string|array $prefix): array
    {
        $prefixes = is_array($prefix) ? $prefix : [$prefix];
        $urlId = null;

        foreach ($prefixes as $p) {
            if (str_starts_with($_SERVER['REQUEST_URI'], $p)) {
                $urlId = substr($_SERVER['REQUEST_URI'], strlen($p));
                break;
            }
        }

        if ($urlId === null) {
            $urlId = substr($_SERVER['REQUEST_URI'], strlen($prefixes[0]));
        }

        $urlId = explode('?', $urlId)[0]; // 移除查询参数
        $urlId = trim($urlId, '/');

        if (empty($urlId)) {
            throw new ApiError(400, "ID is required");
        }

        // 支持多 ID（逗号分隔）
        $ids = explode(',', $urlId);
        $ids = array_map('trim', $ids);
        $ids = array_filter($ids, fn($id) => !empty($id));

        if (empty($ids)) {
            throw new ApiError(400, "At least one valid ID is required");
        }

        // 验证每个 ID 格式
        foreach ($ids as $id) {
            if (!self::isValidId($id)) {
                throw new ApiError(400, "Invalid ID format: {$id}");
            }
        }

        return $ids;
    }

    /**
     * 从 URL 路径提取单个 ID
     *
     * @param string $prefix URL 前缀
     * @return string ID
     * @throws ApiError
     */
    public static function extractId(string|array $prefix): string
    {
        $ids = self::extractIds($prefix);
        return $ids[0];
    }

    /**
     * 验证请求内容类型
     *
     * @param string|array $allowedTypes 允许的内容类型
     * @return void
     * @throws ApiError
     */
    public static function validateContentType(string|array $allowedTypes): void
    {
        $allowedTypes = is_array($allowedTypes) ? $allowedTypes : [$allowedTypes];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        // 提取主要类型（移除 charset 等参数）
        $contentType = explode(';', $contentType)[0];
        $contentType = trim($contentType);

        foreach ($allowedTypes as $allowedType) {
            if (stripos($contentType, $allowedType) !== false) {
                return;
            }
        }

        throw new ApiError(
            415,
            "Unsupported Media Type. Expected: " . implode(' or ', $allowedTypes)
        );
    }

    /**
     * 验证请求体不为空
     *
     * @param string|null $content 请求内容
     * @return void
     * @throws ApiError
     */
    public static function validateContent(?string $content): void
    {
        if ($content === null || trim($content) === '') {
            throw new ApiError(400, "Request body cannot be empty");
        }
    }
}
