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
    /** 单例创建时索引文件的 mtime；rag:build 通过 rename 原子替换索引文件后
     * mtime 变化，此时丢弃旧单例重建连接，Web 进程无需重启即可用上新索引。 */
    private static ?int $searchIndexMtime = null;

    /**
     * 进程级复用 RagSearch（含 SQLite PDO 连接），避免每个 MCP 请求重建连接。
     *
     * 协程安全性：Swoole 为单线程协程模型，SQLite 查询是同步文件操作（期间不
     * yield），同一时刻仅一个协程执行，共享 PDO 无并发串扰；本地查询 <1ms，
     * 同步阻塞可接受。
     */
    private function getSearch(): RagSearch
    {
        $currentMtime = self::currentIndexMtime();
        if (self::$search === null || self::$searchIndexMtime !== $currentMtime) {
            self::$search = new RagSearch(RagSearch::resolveDbPath());
            self::$searchIndexMtime = $currentMtime;
        }
        return self::$search;
    }

    private static function currentIndexMtime(): ?int
    {
        $mtime = @filemtime(RagSearch::resolveDbPath());
        return $mtime === false ? null : $mtime;
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
            if ($token === '' || !hash_equals('Bearer ' . $token, $authorization)) {
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
                                'description' => '在内置知识库中检索已验证的实战资料。知识库覆盖：常见崩溃与故障模式（mixin 注入失败、内存不足、Java 版本错误等）、'
                                    . '移动端启动器生态实战案例蒸馏（FCL/Zalith/Amethyst/PGW/MobileGlues，含排障决策树）、三大日志文件格式解读、'
                                    . 'Fabric/Forge/NeoForge 与 PaperMC/Purpur/Geyser 等开发文档。日志中出现异常类名、崩溃特征或启动器相关问题时优先使用；'
                                    . '纯常识问题不必使用。返回带来源路径的文档片段，多数条目按「签名-含义-解决方案」组织。',
                                'inputSchema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'query' => ['type' => 'string', 'description' => '检索词。直接使用日志中的原文信号：英文异常类名或错误串（如 MixinApplyError、SIGSEGV、OutOfMemoryError），或中文症状关键词（如 内存不足、启动闪退）。不要翻译或改写异常类名。'],
                                        'topic' => ['type' => 'string', 'description' => '可选。限定在某个主题目录内检索（目录名来自 list_topics 的主题地图），如 "patterns"、"日志分析"。省略则在全库检索。'],
                                        'k' => ['type' => 'number', 'description' => '返回片段数量，默认 5'],
                                    ],
                                    'required' => ['query'],
                                ],
                            ],
                            [
                                'name' => 'list_topics',
                                'description' => '列出内置知识库的主题地图（目录、说明与内容样本）。不确定检索方向、或 rag_search 连续无结果时调用；'
                                    . '看完地图后应带着明确目标词去 rag_search（可配合 topic 参数定向），不要看完地图就停止分析。',
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
                        $text = self::formatTopics($rag->topics(), $rag->stats());
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

                    // 归一化与校验统一在 RagSearch::normalizeTopic()：非法值抛
                    // InvalidArgumentException，自动落入下方 -32602 分支回传模型
                    $topic = RagSearch::normalizeTopic($arguments['topic'] ?? null);

                    $k = isset($arguments['k']) ? (int) $arguments['k'] : 5;
                    $results = $rag->search($query, $k, $topic);

                    $text = self::formatResults($results, $rag->stats(), $topic);
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
     * @param string|null $topic 归一化后的定向目录（回显用）
     * @return string
     */
    private static function formatResults(array $results, array $stats, ?string $topic = null): string
    {
        if (empty($results)) {
            $scope = $topic !== null ? '，目录范围：' . $topic : '';
            $lines = ['未在知识库中找到相关文档（共 ' . $stats['chunks'] . ' 个分块' . $scope . '）。下一步建议：'];
            $lines[] = '1. 换用日志中的异常类名或错误串原词（如 MixinApplyError）再检索一次；';
            if ($topic !== null) {
                $lines[] = '2. 去掉 topic 限定扩大到全库（list_topics 可查看目录清单）；';
            } else {
                $lines[] = '2. 调用 list_topics 查看目录清单，用 topic 参数定向检索；';
            }
            $lines[] = '3. 若知识库确实未覆盖此问题，改用 web_search_exa 搜索网络。';
            return implode("\n", $lines);
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
    private static function formatTopics(array $topics, array $stats): string
    {
        if (empty($topics)) {
            return "知识库为空（共 " . $stats['chunks'] . " 个分块）。";
        }

        $lines = [
            '知识库共 ' . count($topics) . ' 个主题目录、' . $stats['chunks'] . ' 个分块。'
            . '检索前先浏览目录与说明选定方向；rag_search 可用 topic 参数在目录内定向检索。',
        ];
        foreach ($topics as $topic) {
            $head = '■ ' . $topic['dir'] . '（' . $topic['count'] . ' 篇）';
            if (($topic['description'] ?? '') !== '') {
                $head .= '— ' . $topic['description'];
            }
            $lines[] = $head;
            // 样本压缩到 4 个/目录、单个样本名截断至 48 字节、条目间不留空行：
            // 实测全量渲染 7KB 超预算，紧凑版 ~5KB（守门上限 5120B，
            // 见单测 formatTopics output length bounded）
            $names = array_map(
                fn($f) => strlen($f) > 48 ? mb_strcut($f, 0, 48) . '…' : $f,
                array_slice($topic['files'], 0, 4)
            );
            $lines[] = '  样本：' . implode(' / ', $names);
        }

        return implode("\n", $lines);
    }
}
