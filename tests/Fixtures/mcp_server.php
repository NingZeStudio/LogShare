<?php

header('Content-Type: application/json');

$body = file_get_contents('php://input');
$req = json_decode($body, true);
$method = $req['method'] ?? '';
$id = $req['id'] ?? null;

$response = ['jsonrpc' => '2.0', 'id' => $id];

switch ($method) {
    case 'initialize':
        $response['result'] = [
            'protocolVersion' => '2025-03-26',
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'test-mcp-server', 'version' => '1.0.0'],
        ];
        break;

    case 'tools/list':
        $response['result'] = [
            'tools' => [
                ['name' => 'echo', 'description' => 'Echo text', 'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]]],
                ['name' => 'search', 'description' => 'Search something', 'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]],
            ],
        ];
        break;

    case 'tools/call':
        $params = $req['params'] ?? [];
        $name = $params['name'] ?? '';
        $args = (array) ($params['arguments'] ?? []);
        if ($name === 'echo') {
            $response['result'] = ['content' => [['type' => 'text', 'text' => 'echo: ' . ($args['text'] ?? '')]]];
        } else {
            $response['result'] = ['content' => [['type' => 'text', 'text' => 'result for ' . ($args['query'] ?? '')]]];
        }
        break;

    default:
        $response['error'] = ['code' => -32601, 'message' => 'Method not found'];
        http_response_code(400);
}

echo json_encode($response);