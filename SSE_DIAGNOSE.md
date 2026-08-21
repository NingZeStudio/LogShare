# AI 接口 SSE 慢（TTFB 无响应）诊断清单

## 问题背景

- 现象：`GET /v1/ai/{id}` 请求发出后，很久没有任何输出（连 HTTP header 都不返回）；一旦开始输出，流式速度正常。
- 直接调用上游 API（token.sensenova.cn）很快（TTFB ~1.5s）。
- LogShare 其它端点（raw / log）很快（TTFB ~0.4s）。
- 已排除：上游慢、MongoDB 慢、公网到服务器网络慢。
- 疑似：PHP 或 nginx 侧对 SSE 响应做缓冲，导致 header 和首个数据块被攒住直到请求结束。

## 请在服务器上执行以下命令，并把输出贴回来

### 1. nginx 实际生效配置（确认 fastcgi_buffering / gzip / 反代层）

```bash
docker exec mclogs-nginx nginx -T 2>&1
```

### 2. PHP 输出缓冲状态

```bash
docker exec mclogs-php php -r 'var_dump(ob_get_level(), ini_get("output_buffering"), ini_get("zlib.output_compression"), ini_get("implicit_flush"));'
```

### 3. 服务器内部直连测试（绕开公网，确认 header 能否立即出来）

```bash
docker exec mclogs-php curl -sS -N -D - -o /dev/null --max-time 15 'http://nginx:9300/v1/ai/evCWcbq' -H 'Accept: text/event-stream'
```

### 4. 服务器容器到上游的网络延迟

```bash
docker exec mclogs-php curl -sS -o /dev/null -w 'ttfb=%{time_starttransfer}\n' --max-time 30 'https://token.sensenova.cn/v1/chat/completions' -H 'Content-Type: application/json' -H 'Authorization: Bearer sk-7xV6rszgwKRzsGU3CPwwn55LP2AwoWBb' -H 'Accept: text/event-stream' --data-binary '{"model":"deepseek-v4-flash","stream":true,"messages":[{"role":"user","content":"hi"}]}'
```

## 结果解读

- 第 2 条中 `output_buffering` 不为 0 或 `zlib.output_compression` 为 1：说明 PHP 层在缓冲，需在 `startSSE()` 中强制关闭（`ini_set` + 循环清空所有输出缓冲）。
- 第 3 条若 header 正常立即返回：说明公网/外层反代有问题；若 header 也出不来：问题在 nginx fastcgi 或 PHP 层。
- 第 4 条 `ttfb` 很小且正常：排除服务器→上游网络问题，焦点回到缓冲。
