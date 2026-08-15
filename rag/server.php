<?php

/**
 * RAG MCP server over Streamable HTTP (JSON-RPC 2.0).
 *
 * Exposes a single tool: rag_search(query, k). Backed by SQLite FTS5 (BM25).
 *
 * Run:
 *   php -S 127.0.0.1:8081 rag/server.php
 *
 * Configure LogShare with ai.mcp.rag.url = http://127.0.0.1:8081
 *
 * Security: this server has no authentication (internal knowledge base). Bind it
 * to loopback for local use, or put it behind an nginx reverse proxy with access
 * control when exposing it on a network.
 */

require_once __DIR__ . '/RagSearch.php';

$dbPath = RagSearch::resolveDbPath();

header('Content-Type: application/json');
header('Cache-Control: no-cache');

try {
    $rag = new RagSearch($dbPath);
} catch (Throwable $e) {
    error_log("[RAG] 数据库不可用: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32603, 'message' => 'RAG database unavailable']], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$request = $rawBody !== false ? json_decode($rawBody, true) : null;

if (!is_array($request) || !isset($request['method'])) {
    http_response_code(400);
    echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Invalid JSON-RPC request']]);
    exit;
}

$id = $request['id'] ?? null;
$method = (string) $request['method'];
$params = $request['params'] ?? [];

$response = ['jsonrpc' => '2.0', 'id' => $id];

try {
    switch ($method) {
        case 'initialize':
            $response['result'] = [
                'protocolVersion' => '2025-03-26',
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'logshare-rag', 'version' => '1.0.0'],
            ];
            break;

        case 'tools/list':
            $response['result'] = [
                'tools' => [
                    [
                        'name' => 'rag_search',
                        'description' => '在内部知识库中检索相关文档片段。用于查找已知错误与解决方案。',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => '检索关键词，使用错误类名或报错关键词'],
                                'k' => ['type' => 'number', 'description' => '返回片段数量，默认 5'],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                ],
            ];
            break;

        case 'tools/call':
            $name = $params['name'] ?? '';
            $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

            if ($name !== 'rag_search') {
                throw new RuntimeException('Unknown tool: ' . $name);
            }

            $query = trim((string) ($arguments['query'] ?? ''));
            if ($query === '') {
                throw new InvalidArgumentException('rag_search requires a non-empty query');
            }

            $k = isset($arguments['k']) ? (int) $arguments['k'] : 5;
            $results = $rag->search($query, $k);

            $text = formatResults($results, $rag->stats());
            $response['result'] = [
                'content' => [
                    ['type' => 'text', 'text' => $text],
                ],
            ];
            break;

        case 'ping':
            $response['result'] = new stdClass();
            break;

        default:
            throw new RuntimeException('Method not found: ' . $method);
    }
} catch (Throwable $e) {
    $response['error'] = ['code' => -32602, 'message' => $e->getMessage()];
    http_response_code(400);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

/**
 * @param array $results
 * @param array $stats
 * @return string
 */
function formatResults(array $results, array $stats): string
{
    if (empty($results)) {
        return "未在知识库中找到相关文档（共 " . $stats['chunks'] . " 个分块）。可尝试更换关键词，或使用 web_search_exa 搜索网络。";
    }

    $lines = ["在知识库中找到 " . count($results) . " 条相关文档：", ""];
    foreach ($results as $i => $result) {
        $lines[] = '[' . ($i + 1) . '] ' . $result['title'] . '（来源: ' . $result['source'] . '）';
        $body = preg_replace('/\s+/', ' ', $result['body']);
        $lines[] = '    ' . (mb_strlen($body) > 400 ? mb_substr($body, 0, 400) . '…' : $body);
        $lines[] = '';
    }

    return implode("\n", $lines);
}