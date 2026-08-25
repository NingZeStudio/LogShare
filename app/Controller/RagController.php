<?php

declare(strict_types=1);

namespace App\Controller;

use App\Rag\RagSearch;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Psr\Http\Message\ResponseInterface;

/**
 * RAG MCP server over Streamable HTTP (JSON-RPC 2.0), hosted by Hyperf on the
 * main `http` server under the `/rag` prefix. Exposes rag_search / list_topics tools.
 */
#[Controller(prefix: '/rag')]
class RagController extends AbstractController
{
    private const SERVER_VERSION = \App\Version::VERSION;
    private const MAX_QUERY_CHARS = 512;
    private const EXTRA_HOP_HEADERS = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED'];

    private static ?RagSearch $search = null;

    /**
     * 进程级复用 RagSearch（含 SQLite PDO 连接），避免每个 MCP 请求重建连接。
     *
     * 协程安全性：Swoole 为单线程协程模型，SQLite 查询是同步文件操作（期间不
     * yield），同一时刻仅一个协程执行，共享 PDO 无并发串扰；本地查询 <1ms，
     * 同步阻塞可接受。
     */
    private function getSearch(): RagSearch
    {
        if (self::$search === null) {
            self::$search = new RagSearch(RagSearch::resolveDbPath());
        }
        return self::$search;
    }

    #[RequestMapping(path: '', methods: ['GET', 'POST'])]
    public function mcp(): ResponseInterface
    {
        // 访问控制：RAG MCP 端点进程内自用/回环调用为主，不应直接暴露到公网。
        // 未配置 ai.mcp.rag.authToken 时仅接受来自本机回环（127.0.0.1/::1）的
        // 直接连接；配置了 authToken 后，任何来源都必须在 Authorization 头
        // 携带 Bearer <authToken>（else 分支保证外部流量不能绕过 token 校验）。
        $token = (string) (\App\Config::Get('ai')['mcp']['rag']['authToken'] ?? '');
        $server = $this->request->getServerParams();
        $remote = (string) ($server['remote_addr'] ?? '');
        $isLoopback = in_array($remote, ['127.0.0.1', '::1', 'localhost'], true);
        $proxied = array_filter(array_map(
            fn($h) => (string) ($server[$h] ?? ''),
            self::EXTRA_HOP_HEADERS
        ));
        if (!$isLoopback || $proxied !== []) {
            $authorization = $this->request->getHeaderLine('Authorization');
            if ($token === '' || $authorization !== 'Bearer ' . $token) {
                return $this->respondJson([
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => ['code' => -32001, 'message' => 'Unauthorized'],
                ]);
            }
        }

        try {
            $rag = $this->getSearch();
        } catch (\Throwable $e) {
            \App\Syslog::error("RAG", "数据库不可用: " . $e->getMessage());
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
                        'serverInfo' => ['name' => 'logshare-rag', 'version' => self::SERVER_VERSION],
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
                    if (mb_strlen($query) > self::MAX_QUERY_CHARS) {
                        throw new \InvalidArgumentException('rag_search query is too long');
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
                    throw new \App\Exception\McpMethodNotFoundException('Method not found: ' . $method);
            }
        } catch (\InvalidArgumentException $e) {
            // 入参校验类错误：消息由本端点自身产生，可安全回传
            $response['error'] = ['code' => -32602, 'message' => $e->getMessage()];
        } catch (\App\Exception\McpMethodNotFoundException $e) {
            $response['error'] = ['code' => -32601, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            // 内部错误（PDO/IO 等）可能携带数据库路径、schema 等环境细节，
            // /rag 即使通过鉴权后对外部仍保持最小信息暴露，只返回通用消息，细节仅写日志。
            \App\Syslog::error('RAG', 'tool call failed: ' . $e->getMessage());
            $response['error'] = ['code' => -32603, 'message' => 'Internal error'];
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
            // 保留原始换行：Markdown 列表/代码块结构对模型理解「修复步骤」类内容至关重要
            $lines[] = $result['snippet'] ?? $result['body'];
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
