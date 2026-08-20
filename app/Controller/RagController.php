<?php

declare(strict_types=1);

namespace App\Controller;

use App\Rag\RagSearch;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Psr\Http\Message\ResponseInterface;

/**
 * RAG MCP server over Streamable HTTP (JSON-RPC 2.0), hosted by Hyperf on the
 * dedicated `rag` server (port 8081). Exposes rag_search / list_topics tools.
 */
#[Controller(prefix: '/', server: 'rag')]
class RagController extends AbstractController
{
    #[RequestMapping(path: '', methods: ['GET', 'POST'])]
    public function mcp(): ResponseInterface
    {
        try {
            $rag = new RagSearch(RagSearch::resolveDbPath());
        } catch (\Throwable $e) {
            error_log("[RAG] 数据库不可用: " . $e->getMessage());
            return $this->respondJson([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32603, 'message' => 'RAG database unavailable'],
            ]);
        }

        $rawBody = $this->request->getBody()->getContents();
        $request = $rawBody !== '' ? json_decode($rawBody, true) : null;

        if (!is_array($request) || !isset($request['method'])) {
            return $this->respondJson([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Invalid JSON-RPC request'],
            ]);
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
                            [
                                'name' => 'list_topics',
                                'description' => '列出知识库涵盖的主题与文档分布，帮助你决定检索方向。搜索前可先调用本工具了解知识库有什么。',
                                'inputSchema' => [
                                    'type' => 'object',
                                    'properties' => new \stdClass(),
                                ],
                            ],
                        ],
                    ];
                    break;

                case 'tools/call':
                    $name = $params['name'] ?? '';
                    $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

                    if ($name === 'list_topics') {
                        $text = $this->formatTopics($rag->topics(), $rag->stats());
                        $response['result'] = ['content' => [['type' => 'text', 'text' => $text]]];
                        break;
                    }

                    if ($name !== 'rag_search') {
                        throw new \RuntimeException('Unknown tool: ' . $name);
                    }

                    $query = trim((string) ($arguments['query'] ?? ''));
                    if ($query === '') {
                        throw new \InvalidArgumentException('rag_search requires a non-empty query');
                    }

                    $k = isset($arguments['k']) ? (int) $arguments['k'] : 5;
                    $results = $rag->search($query, $k);

                    $text = $this->formatResults($results, $rag->stats());
                    $response['result'] = [
                        'content' => [
                            ['type' => 'text', 'text' => $text],
                        ],
                    ];
                    break;

                case 'ping':
                    $response['result'] = new \stdClass();
                    break;

                default:
                    throw new \RuntimeException('Method not found: ' . $method);
            }
        } catch (\Throwable $e) {
            $response['error'] = ['code' => -32602, 'message' => $e->getMessage()];
        }

        return $this->respondJson($response);
    }

    /**
     * @param array $results
     * @param array $stats
     * @return string
     */
    private function formatResults(array $results, array $stats): string
    {
        if (empty($results)) {
            return "未在知识库中找到相关文档（共 " . $stats['chunks'] . " 个分块）。可尝试更换关键词，或使用 web_search_exa 搜索网络。";
        }

        $lines = ["在知识库中找到 " . count($results) . " 条相关文档：", ""];
        foreach ($results as $i => $result) {
            $lines[] = '[' . ($i + 1) . '] ' . $result['title'] . '（来源: ' . $result['source'] . '）';
            $snippet = preg_replace('/\s+/', ' ', $result['snippet'] ?? $result['body']);
            $lines[] = '    ' . $snippet;
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array $topics
     * @param array $stats
     * @return string
     */
    private function formatTopics(array $topics, array $stats): string
    {
        if (empty($topics)) {
            return "知识库为空（共 " . $stats['chunks'] . " 个分块）。";
        }

        $lines = ["知识库共 " . count($topics) . " 个主题目录、" . $stats['chunks'] . " 个分块：", ""];
        foreach ($topics as $topic) {
            $lines[] = '■ ' . $topic['dir'] . '（' . $topic['count'] . ' 篇）';
            $sample = implode(' / ', $topic['files']);
            $lines[] = '   ' . $sample;
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
