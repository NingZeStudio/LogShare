<?php

class ApiError extends \Exception implements JsonSerializable
{
    protected int $httpCode;

    public function __construct(int $httpCode, string $message)
    {
        $this->httpCode = $httpCode;
        parent::__construct($message, $httpCode);
    }

    public function jsonSerialize(): array
    {
        return [
            'success' => false,
            'error' => $this->message,
        ];
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    /**
     * Output this error as a JSON response and terminate the script
     * @return never
     */
    public function output(): never
    {
        header('Content-Type: application/json');
        http_response_code($this->httpCode);
        echo json_encode($this);
        exit;
    }
}
