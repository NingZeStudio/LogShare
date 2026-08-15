<?php

namespace Client;

/**
 * Lightweight MCP client using the Streamable HTTP transport.
 *
 * Implements just enough of the Model Context Protocol for tool consumption:
 * initialize, tools/list and tools/call over JSON-RPC 2.0 / HTTP POST.
 */
class MCPClient
{
    private string $url;
    private array $headers;
    private int $timeout;
    private int $connectTimeout;
    private ?string $sessionId = null;
    private bool $initialized = false;
    private int $requestId = 0;
    private ?string $protocolVersion = null;

    public function __construct(string $url, array $headers = [], int $timeout = 30, int $connectTimeout = 15)
    {
        $this->url = $url;
        $this->headers = $headers;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * Perform the MCP initialize handshake.
     *
     * @return array Server info from the initialize result
     * @throws \Exception
     */
    public function initialize(): array
    {
        $result = $this->request('initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'logshare', 'version' => '1.6.0'],
        ]);
        $this->initialized = true;
        $this->protocolVersion = $result['protocolVersion'] ?? '2025-03-26';

        return $result;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getProtocolVersion(): ?string
    {
        return $this->protocolVersion;
    }

    /**
     * List the tools exposed by the server.
     *
     * @return array<int, array>
     * @throws \Exception
     */
    public function listTools(): array
    {
        $this->ensureInitialized();
        $result = $this->request('tools/list');
        return $result['tools'] ?? [];
    }

    /**
     * Call a tool and return the extracted text content.
     *
     * @param string $name
     * @param array $arguments
     * @return array<int, string> List of text chunks (e.g. search results)
     * @throws \Exception
     */
    public function callTool(string $name, array $arguments = []): array
    {
        $this->ensureInitialized();
        $result = $this->request('tools/call', [
            'name' => $name,
            'arguments' => (object) $arguments,
        ]);

        $contents = [];
        foreach ($result['content'] ?? [] as $item) {
            if (is_array($item)) {
                if (($item['type'] ?? '') === 'text' && isset($item['text'])) {
                    $contents[] = $item['text'];
                } elseif (($item['type'] ?? '') === 'resource') {
                    $text = $item['text'] ?? $item['blob'] ?? '';
                    if ($text !== '') {
                        $contents[] = is_string($text) ? $text : '';
                    }
                }
            }
        }

        return $contents;
    }

    private function ensureInitialized(): void
    {
        if (!$this->initialized) {
            $this->initialize();
        }
    }

    /**
     * Send a JSON-RPC request and return the result payload.
     *
     * @param string $method
     * @param mixed $params
     * @return array
     * @throws \Exception
     */
    private function request(string $method, mixed $params = null): array
    {
        $this->requestId++;
        $payload = [
            'jsonrpc' => '2.0',
            'id' => $this->requestId,
            'method' => $method,
        ];
        if ($params !== null) {
            $payload['params'] = $params;
        }

        $httpHeaders = $this->headers;
        $httpHeaders[] = 'Content-Type: application/json';
        $httpHeaders[] = 'Accept: application/json, text/event-stream';
        if ($this->sessionId !== null) {
            $httpHeaders[] = 'mcp-session-id: ' . $this->sessionId;
        }

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) {
                $trimmed = trim($headerLine);
                if (stripos($trimmed, 'mcp-session-id:') === 0) {
                    $this->sessionId = trim(substr($trimmed, strlen('mcp-session-id:')));
                }
                return strlen($headerLine);
            },
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($body === false) {
            throw new \Exception('MCP request failed: ' . $curlError);
        }
        if ($httpCode === 429) {
            throw new \Exception('MCP request rate limited (HTTP 429).');
        }
        if ($httpCode >= 400) {
            throw new \Exception('MCP request failed with HTTP ' . $httpCode . ': ' . mb_substr((string) $body, 0, 300));
        }

        $decoded = $this->decodeResponse((string) $body);

        if (isset($decoded['error'])) {
            $error = $decoded['error'];
            $message = $error['message'] ?? 'Unknown MCP error';
            $code = $error['code'] ?? 0;
            throw new \Exception('MCP error ' . $code . ': ' . $message);
        }

        if (!array_key_exists('result', $decoded)) {
            throw new \Exception('MCP response missing result.');
        }

        $result = $decoded['result'];
        return is_array($result) ? $result : ['content' => []];
    }

    /**
     * Decode a JSON-RPC response, tolerating both plain JSON and SSE framing.
     *
     * @param string $body
     * @return array
     */
    private function decodeResponse(string $body): array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fallback: extract JSON from SSE data lines
        $data = '';
        foreach (explode("\n", $body) as $line) {
            if (str_starts_with($line, 'data: ')) {
                $data .= substr($line, 6);
            }
        }

        if ($data === '') {
            return [];
        }

        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : [];
    }
}