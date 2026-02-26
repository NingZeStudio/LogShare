<?php

/**
 * API 响应助手类
 * 用于统一 API 响应格式和输出
 */
class ApiResponse
{
    /**
     * 发送成功响应
     *
     * @param mixed $data 响应数据
     * @param string $message 成功消息
     * @param int $httpCode HTTP 状态码
     * @return void
     */
    public static function success(mixed $data = null, string $message = 'Success', int $httpCode = 200): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');

        $response = new stdClass();
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

        echo json_encode($response);
        exit;
    }

    /**
     * 发送错误响应
     *
     * @param string $message 错误消息
     * @param int $httpCode HTTP 状态码
     * @param mixed $errors 详细错误信息
     * @return void
     */
    public static function error(string $message, int $httpCode = 400, mixed $errors = null): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');

        $response = new stdClass();
        $response->success = false;
        $response->error = $message;
        $response->code = $httpCode;

        if ($errors !== null) {
            $response->errors = $errors;
        }

        echo json_encode($response);
        exit;
    }

    /**
     * 发送 JSON 响应
     *
     * @param mixed $data 响应数据
     * @param int $httpCode HTTP 状态码
     * @return void
     */
    public static function json(mixed $data, int $httpCode = 200): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * 发送纯文本响应
     *
     * @param string $content 响应内容
     * @param string $contentType 内容类型
     * @param int $httpCode HTTP 状态码
     * @return void
     */
    public static function text(string $content, string $contentType = 'text/plain', int $httpCode = 200): void
    {
        http_response_code($httpCode);
        header('Content-Type: ' . $contentType);
        echo $content;
        exit;
    }
}
