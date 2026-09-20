<?php

declare(strict_types=1);

/**
 * LogShare 端到端真实环境冒烟与全链路集成测试脚本。
 *
 * 用法：
 *   php scripts/ci_e2e_test.php --base-url=http://127.0.0.1:9501 [--admin-token=xxx] [--waf-test]
 */

$options = getopt('', ['base-url:', 'admin-token::', 'waf-test::']);
$baseUrl = rtrim((string) ($options['base-url'] ?? 'http://127.0.0.1:9501'), '/');
$adminToken = (string) ($options['admin-token'] ?? 'test-admin-secret-token');
$testWaf = isset($options['waf-test']);

echo "=== 开始 LogShare 真实环境端到端测试 ===\n";
echo "目标地址: {$baseUrl}\n";
echo "WAF/网关测试模式: " . ($testWaf ? "已启用" : "未启用") . "\n";

$testsRun = 0;
$testsPassed = 0;

function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FORBID_REUSE, true);

    $formattedHeaders = [];
    foreach ($headers as $k => $v) {
        $formattedHeaders[] = "{$k}: {$v}";
    }
    if (!empty($formattedHeaders)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
    }

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL 请求失败: {$err} for {$method} {$url}");
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $headerStr = substr($raw, 0, $headerSize);
    $bodyStr = substr($raw, $headerSize);

    return [
        'status' => $statusCode,
        'headers' => $headerStr,
        'body' => $bodyStr,
    ];
}

function runCheck(string $name, callable $fn): void
{
    global $testsRun, $testsPassed;
    $testsRun++;
    echo "[TEST {$testsRun}] {$name} ... ";
    try {
        $fn();
        $testsPassed++;
        echo "PASS\n";
    } catch (Throwable $e) {
        echo "FAIL\n";
        echo "  错误: " . $e->getMessage() . "\n";
        echo "  位置: " . $e->getFile() . ":" . $e->getLine() . "\n";
        exit(1);
    }
}

// ── 1. 公共接口与配置健康检查 ──

runCheck('GET /v1/limits 存储限制与大小配置', function () use ($baseUrl) {
    $res = httpRequest('GET', "{$baseUrl}/v1/limits");
    if ($res['status'] !== 200) {
        throw new RuntimeException("状态码非 200: {$res['status']}");
    }
    $json = json_decode($res['body'], true);
    if (!isset($json['storageTime'], $json['maxSize'])) {
        throw new RuntimeException("响应中缺少 storageTime 或 maxSize 字段: {$res['body']}");
    }
});

runCheck('GET /v1/filters 过滤器链路检查', function () use ($baseUrl) {
    $res = httpRequest('GET', "{$baseUrl}/v1/filters");
    if ($res['status'] !== 200) {
        throw new RuntimeException("状态码非 200: {$res['status']}");
    }
    $json = json_decode($res['body'], true);
    if (!isset($json['filters']) || !is_array($json['filters'])) {
        throw new RuntimeException("响应中缺少 filters 列表: {$res['body']}");
    }
});

runCheck('GET / 根路径 API 目录检查', function () use ($baseUrl) {
    $res = httpRequest('GET', "{$baseUrl}/");
    if ($res['status'] !== 200) {
        throw new RuntimeException("状态码非 200: {$res['status']}");
    }
    $json = json_decode($res['body'], true);
    if (!isset($json['endpoints']) || !is_array($json['endpoints'])) {
        throw new RuntimeException("根路径缺少 endpoints 数组: {$res['body']}");
    }
});

// ── 2. 日志完整生命周期：写入、附件、读取、分析、元数据、删除、404 闭环 ──

$logId = null;
$logToken = null;

runCheck('POST /v1/log 创建日志并包含附件文件', function () use ($baseUrl, &$logId, &$logToken) {
    $payload = json_encode([
        'content' => "[12:00:00] [Server thread/INFO]: Minecraft server starting\n[12:00:01] [Server thread/ERROR]: Exception in thread 'main' java.lang.RuntimeException: Test crash\n",
        'source' => 'e2e-runner/1.0',
        'files' => [
            ['name' => 'crash-report.txt', 'data' => "Time: 2026-09-20\nDescription: Crash during e2e test\n"],
            ['name' => 'server.properties', 'data' => "server-port=25565\nmotd=A Minecraft Server\n"],
        ],
    ]);

    $res = httpRequest('POST', "{$baseUrl}/v1/log", ['Content-Type' => 'application/json'], $payload);
    if ($res['status'] !== 200) {
        throw new RuntimeException("创建日志失败，状态码 {$res['status']}: {$res['body']}");
    }
    $json = json_decode($res['body'], true);
    if (empty($json['success']) || empty($json['id']) || empty($json['token'])) {
        throw new RuntimeException("返回数据不完整: {$res['body']}");
    }

    $logId = $json['id'];
    $logToken = $json['token'];
});

runCheck('GET /v1/log/{id} 获取日志内容与附件元数据', function () use ($baseUrl, &$logId) {
    $res = httpRequest('GET', "{$baseUrl}/v1/log/{$logId}");
    if ($res['status'] !== 200) {
        throw new RuntimeException("获取日志失败，状态码 {$res['status']}: {$res['body']}");
    }
    $json = json_decode($res['body'], true);
    if (empty($json['success']) || $json['id'] !== $logId) {
        throw new RuntimeException("返回的日志 ID 不匹配: {$res['body']}");
    }
    if (!str_contains($json['data'], 'Minecraft server starting')) {
        throw new RuntimeException("日志内容未包含预期文本");
    }
    if (!isset($json['files']) || count($json['files']) !== 2) {
        throw new RuntimeException("附件文件列表数量不符合预期");
    }
});

runCheck('GET /v1/raw/{id} 获取原始纯文本日志', function () use ($baseUrl, &$logId) {
    $res = httpRequest('GET', "{$baseUrl}/v1/raw/{$logId}");
    if ($res['status'] !== 200) {
        throw new RuntimeException("获取原始日志失败: {$res['status']}");
    }
    if (!str_contains($res['body'], 'Minecraft server starting')) {
        throw new RuntimeException("原始日志内容不匹配: {$res['body']}");
    }
});

runCheck('GET /v1/raw/{id}/{filename} 获取指定附加文件内容', function () use ($baseUrl, &$logId) {
    $res = httpRequest('GET', "{$baseUrl}/v1/raw/{$logId}/crash-report.txt");
    if ($res['status'] !== 200) {
        throw new RuntimeException("获取附加文件失败: {$res['status']}");
    }
    if (!str_contains($res['body'], 'Crash during e2e test')) {
        throw new RuntimeException("附加文件内容不匹配: {$res['body']}");
    }
});

runCheck('GET /v1/meta/{id} 获取日志元数据信息', function () use ($baseUrl, &$logId) {
    $res = httpRequest('GET', "{$baseUrl}/v1/meta/{$logId}");
    if ($res['status'] !== 200) {
        throw new RuntimeException("获取元数据失败: {$res['status']}");
    }
    $json = json_decode($res['body'], true);
    if (empty($json['success']) || $json['id'] !== $logId || empty($json['files'])) {
        throw new RuntimeException("元数据响应不合法: {$res['body']}");
    }
});

runCheck('GET /v1/insights/{id} Codex 日志特征解析', function () use ($baseUrl, &$logId) {
    $res = httpRequest('GET', "{$baseUrl}/v1/insights/{$logId}");
    if ($res['status'] !== 200) {
        throw new RuntimeException("Codex insights 解析失败: {$res['status']}");
    }
    $json = json_decode($res['body'], true);
    if (!is_array($json)) {
        throw new RuntimeException("insights 响应非 JSON 结构: {$res['body']}");
    }
});

runCheck('POST /v1/analyse 直发日志分析', function () use ($baseUrl) {
    $payload = json_encode([
        'content' => "[12:00:00] [main/FATAL]: Failed to start the minecraft server\njava.lang.NullPointerException: Cannot invoke method\n",
    ]);
    $res = httpRequest('POST', "{$baseUrl}/v1/analyse", ['Content-Type' => 'application/json'], $payload);
    if ($res['status'] !== 200) {
        throw new RuntimeException("直发分析失败: {$res['status']}");
    }
    $json = json_decode($res['body'], true);
    if (!is_array($json)) {
        throw new RuntimeException("分析响应非合法结构");
    }
});

runCheck('POST /v1/telemetry/report 上报性能遥测数据', function () use ($baseUrl) {
    $payload = json_encode([
        'items' => [
            [
                'type' => 'api',
                'endpoint' => '/v1/log',
                'method' => 'POST',
                'duration' => 15.5,
                'status' => 200,
                'timestamp' => time(),
            ],
            [
                'type' => 'web',
                'page' => '/log/view',
                'duration' => 24.2,
                'timestamp' => time(),
            ],
        ],
    ]);
    $res = httpRequest('POST', "{$baseUrl}/v1/telemetry/report", ['Content-Type' => 'application/json'], $payload);
    if ($res['status'] !== 200) {
        throw new RuntimeException("遥测上报失败: {$res['status']}");
    }
    $json = json_decode($res['body'], true);
    if (empty($json['success']) || ($json['processed'] ?? 0) < 1) {
        throw new RuntimeException("遥测上报响应不合法: {$res['body']}");
    }
});

runCheck('DELETE /v1/log/{id} 鉴权拦截与有效 Token 成功删除', function () use ($baseUrl, &$logId, &$logToken) {
    // 1. 无 Token
    $unauthRes = httpRequest('DELETE', "{$baseUrl}/v1/log/{$logId}");
    if ($unauthRes['status'] !== 401) {
        throw new RuntimeException("无 Token 删除应被 401 拦截，实际状态: {$unauthRes['status']}");
    }

    // 2. 错误 Token
    $badTokenRes = httpRequest('DELETE', "{$baseUrl}/v1/log/{$logId}", ['Authorization' => 'Bearer bad-token']);
    if ($badTokenRes['status'] !== 400 && $badTokenRes['status'] !== 403) {
        throw new RuntimeException("错误 Token 应被拒绝，实际状态: {$badTokenRes['status']}");
    }

    // 3. 正确 Token 成功删除
    $goodRes = httpRequest('DELETE', "{$baseUrl}/v1/log/{$logId}", ['Authorization' => "Bearer {$logToken}"]);
    if ($goodRes['status'] !== 200) {
        throw new RuntimeException("正确 Token 删除失败，状态: {$goodRes['status']}: {$goodRes['body']}");
    }

    // 4. 重复删除返回 400/404
    $againRes = httpRequest('DELETE', "{$baseUrl}/v1/log/{$logId}", ['Authorization' => "Bearer {$logToken}"]);
    if ($againRes['status'] !== 400 && $againRes['status'] !== 404) {
        throw new RuntimeException("重复删除应失败，实际状态: {$againRes['status']}");
    }

    // 5. 再次查询返回 404
    $getAfterDel = httpRequest('GET', "{$baseUrl}/v1/log/{$logId}");
    if ($getAfterDel['status'] !== 404) {
        throw new RuntimeException("删除后获取日志应返回 404，实际: {$getAfterDel['status']}");
    }
});

// ── 3. RAG MCP Streamable HTTP 协议验证 ──

runCheck('POST /rag MCP JSON-RPC 2.0 initialize 协议握手', function () use ($baseUrl) {
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2024-11-05',
            'clientInfo' => ['name' => 'e2e-test', 'version' => '1.0'],
        ],
    ]);
    $res = httpRequest('POST', "{$baseUrl}/rag", ['Content-Type' => 'application/json'], $payload);
    if ($res['status'] !== 200) {
        throw new RuntimeException("MCP initialize 失败: {$res['status']}");
    }
    $json = json_decode($res['body'], true);
    if (!isset($json['result']['serverInfo']['name']) || $json['result']['serverInfo']['name'] !== 'logshare-rag') {
        throw new RuntimeException("MCP initialize 结果不合法: {$res['body']}");
    }
});

runCheck('POST /rag MCP tools/list 与 list_topics / rag_search 声明', function () use ($baseUrl) {
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
    ]);
    $res = httpRequest('POST', "{$baseUrl}/rag", ['Content-Type' => 'application/json'], $payload);
    if ($res['status'] !== 200) {
        throw new RuntimeException("MCP tools/list 失败: {$res['status']}");
    }
    $json = json_decode($res['body'], true);
    $tools = array_column($json['result']['tools'] ?? [], 'name');
    if (!in_array('rag_search', $tools, true) || !in_array('list_topics', $tools, true)) {
        throw new RuntimeException("MCP 工具集不完整: " . implode(', ', $tools));
    }
});

runCheck('POST /rag MCP tools/call (list_topics)', function () use ($baseUrl) {
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => [
            'name' => 'list_topics',
            'arguments' => new stdClass(),
        ],
    ]);
    $res = httpRequest('POST', "{$baseUrl}/rag", ['Content-Type' => 'application/json'], $payload);
    if ($res['status'] !== 200) {
        throw new RuntimeException("MCP 调用 list_topics 失败: {$res['status']}");
    }
    $json = json_decode($res['body'], true);
    if (!isset($json['result']['content'][0]['text'])) {
        throw new RuntimeException("list_topics 返回内容不合法: {$res['body']}");
    }
});

// ── 4. Admin 管理接口验证 ──

runCheck('GET /v1/admin/logs 需授权拦截与授权访问', function () use ($baseUrl, $adminToken) {
    // 1. 无授权拦截
    $unauth = httpRequest('GET', "{$baseUrl}/v1/admin/logs");
    if ($unauth['status'] !== 401 && $unauth['status'] !== 403) {
        throw new RuntimeException("管理接口未授权访问未被拦截，状态: {$unauth['status']}");
    }

    // 2. 携带 Token 授权访问
    $auth = httpRequest('GET', "{$baseUrl}/v1/admin/logs?page=1&limit=10", [
        'Authorization' => "Bearer {$adminToken}",
    ]);
    // 若 admin 未启用则为 403，若启用则为 200
    if ($auth['status'] === 200) {
        $json = json_decode($auth['body'], true);
        if (empty($json['success']) || !isset($json['items'])) {
            throw new RuntimeException("管理端日志列表响应不合法: {$auth['body']}");
        }
    }
});

runCheck('GET /v1/admin/system/stats 与 storage-health 系统状态检查', function () use ($baseUrl, $adminToken) {
    $stats = httpRequest('GET', "{$baseUrl}/v1/admin/system/stats", [
        'Authorization' => "Bearer {$adminToken}",
    ]);
    if ($stats['status'] === 200) {
        $json = json_decode($stats['body'], true);
        if (empty($json['success']) || !isset($json['data'])) {
            throw new RuntimeException("系统统计数据不合法: {$stats['body']}");
        }
    }

    $health = httpRequest('GET', "{$baseUrl}/v1/admin/system/storage-health", [
        'Authorization' => "Bearer {$adminToken}",
    ]);
    if ($health['status'] === 200) {
        $json = json_decode($health['body'], true);
        if (empty($json['success']) || !isset($json['data'])) {
            throw new RuntimeException("存储健康度数据不合法: {$health['body']}");
        }
    }
});

// ── 5. WAF 与边缘安全网关测试（若开启 --waf-test） ──

if ($testWaf) {
    runCheck('WAF 统计端点 GET /security/stats 正常响应', function () use ($baseUrl) {
        $res = httpRequest('GET', "{$baseUrl}/security/stats");
        if ($res['status'] !== 200) {
            throw new RuntimeException("安全统计页不可达: {$res['status']}");
        }
        $json = json_decode($res['body'], true);
        if (!isset($json['attacks_blocked'], $json['banned_ips_count'])) {
            throw new RuntimeException("安全统计数据缺少必要字段: {$res['body']}");
        }
    });

    runCheck('站点统计端点 GET /stats/data 正常响应', function () use ($baseUrl) {
        $res = httpRequest('GET', "{$baseUrl}/stats/data");
        if ($res['status'] !== 200) {
            throw new RuntimeException("站点统计数据不可达: {$res['status']}");
        }
        $json = json_decode($res['body'], true);
        if (!isset($json['today'])) {
            throw new RuntimeException("站点统计缺少 today 统计对象: {$res['body']}");
        }
    });

    runCheck('OpenLiteWaf 真实恶意攻击拦截 (SQL 注入 / 路径遍历)', function () use ($baseUrl) {
        // 尝试发送典型路径遍历请求
        $attackRes = httpRequest('GET', "{$baseUrl}/v1/log?id=../../../../etc/passwd");
        if ($attackRes['status'] !== 403) {
            throw new RuntimeException("OpenLiteWaf 未拦截路径遍历攻击，状态: {$attackRes['status']}");
        }

        // 尝试发送典型 SQL 注入请求
        $sqliRes = httpRequest('GET', "{$baseUrl}/v1/limits?union=SELECT%201,version()");
        if ($sqliRes['status'] !== 403) {
            throw new RuntimeException("OpenLiteWaf 未拦截 SQL 注入攻击，状态: {$sqliRes['status']}");
        }
    });

    runCheck('OpenLiteWaf 白名单豁免验证 (带报错异常的日志 POST 放行)', function () use ($baseUrl) {
        $payload = json_encode([
            'content' => "[12:00:00] [ERROR]: org.sqlite.SQLiteException: [SQLITE_ERROR] syntax error near 'SELECT * FROM'\n",
        ]);
        $res = httpRequest('POST', "{$baseUrl}/v1/log", ['Content-Type' => 'application/json'], $payload);
        if ($res['status'] !== 200) {
            throw new RuntimeException("OpenLiteWaf 误拦截正常日志上传，状态: {$res['status']}: {$res['body']}");
        }
    });
}

echo "=== 全部 {$testsPassed}/{$testsRun} 项端到端测试均成功通过 ===\n";
