# LiteWAF

附身于 Nginx 容器的极简 WAF，由 OpenResty（Nginx + lua-nginx-module）承载。目标不是替代企业级 WAF，而是拦截最常见的低水平攻击：CC 攻击、SQL 注入 / XSS / 路径穿越探测、扫描器（sqlmap、nikto、gobuster 等）的脚本小子式行为。触发拦截时返回 403 中文警告页，并提供一个可公开访问的极简安全统计页。

## 能力与取舍

- **CC 防御**：基于 `lua_shared_dict` 的固定窗口计数（默认 10 秒窗口 240 次），超限封禁 IP（默认 600 秒），全 worker 共享、原子计数。
- **特征拦截**：PCRE 规则按顺序匹配原始 `request_uri`、解码后 `uri` 与 `User-Agent`，命中即封禁并返回 403 警告页。
- **警告页**：触发时返回中文 403 页面，说明拦截原因（CC 频率超限 / 恶意攻击特征）。
- **公开统计页**：`/security`（HTML）与 `/security/stats`（JSON），仅暴露内存计数（累计请求、拦截数、各类目分布、运行时长），不含 IP 等敏感信息；数据不持久化，进程重启清零。
- **已知边界（有意取舍）**：固定窗口计数在窗口交界处存在约 2×limit 的瞬时突发余量（固定窗口的固有特性，非缺陷）；短时高频脉冲（瞬时超过 nginx `limit_req` 的 burst）会在 PREACCESS 阶段被 503 丢弃、不会进入 LiteWAF 触发封禁，由静态限速兜底；如需覆盖短脉冲可增加 1 秒级细粒度窗口作为第二道阈值。
- **刻意不做**（保持极简与零误报）：不检查请求体（本站日志正文经由 POST body，做特征扫描只会徒增误报与开销）；无 IP 信誉库、无 JS 挑战、无持久化。高
强度抗 CC 请交给前置 CDN/WAF。

## 目录结构

```
LiteWAF/
├── README.md            # 本文档
├── lua/
│   ├── litewaf.lua      # 核心模块：配置、规则、CC、封禁、警告页、统计页
│   ├── waf.lua          # access_by_lua_file 入口
│   └── stats.lua        # content_by_lua_file 入口（/security）
└── nginx/
    └── nginx.conf       # OpenResty 主配置（http 级 lua 指令 + shared dict）
```

## 集成方式（LogShare 默认已接好）

`docker/compose.yaml` 中 nginx 服务使用 `openresty/openresty:1.27.1.2-alpine`，并做三处挂载：

```yaml
volumes:
  - ./nginx:/etc/nginx/conf.d:ro                                    # 站点配置
  - ../LiteWAF/nginx/nginx.conf:/usr/local/openresty/nginx/conf/nginx.conf:ro
  - ../LiteWAF/lua:/usr/local/openresty/nginx/lua:ro
```

站点配置 `docker/nginx/default.conf` 在两个 server 中加入：

```nginx
access_by_lua_file /usr/local/openresty/nginx/lua/waf.lua;   # 80 与 443 均有
location = /security       { content_by_lua_file /usr/local/openresty/nginx/lua/stats.lua; }
location = /security/stats { content_by_lua_file /usr/local/openresty/nginx/lua/stats.lua; }   # 仅 443
```

独立使用时只需在任意 OpenResty 环境复刻这三点：http 级声明（见 `nginx/nginx.conf`）`lua_package_path`、`lua_shared_dict litewaf 16m` 与 `init_by_lua_block`，再在 server/location 中挂上 `access_by_lua_file` 与统计页 location。

> 升级 OpenResty 镜像 tag 时（当前固定 `1.27.1.2-alpine`，上游存在 `-7` 补丁系列），同步核对 `nginx/nginx.conf` 与镜像内置配置的差异（lua 指令、临时路径、默认 include），必要时重新挂载。

## 配置

全部集中在 `lua/litewaf.lua` 顶部的 `CONFIG` 表：

| 配置 | 默认值 | 说明 |
| --- | --- | --- |
| `cc.window` / `cc.limit` | 10 / 240 | 窗口秒数 / 窗口内请求上限，超过即封禁 |
| `cc.ban` | 600 | CC 封禁秒数 |
| `sig_ban` | 600 | 命中特征后的封禁秒数 |
| `whitelist_prefixes` | `/.well-known/acme-challenge/` | 完全白名单，不计数不检查 |
| `stats_prefix` | `/security` | 统计页前缀，跳过特征匹配但受 CC 限流 |
| `dict_name` | `litewaf` | 须与 `lua_shared_dict` 名一致 |

**阈值调优要点**：nginx 静态 `limit_req`（PREACCESS 阶段，当前 30r/s burst=60）先于 LiteWAF（ACCESS 阶段）执行，超额请求会被 503 丢弃。因此 `cc.limit` 必须折算后低于 `limit_req` 速率，封禁才能在丢弃之前生效；调整任一侧时请同步检查另一侧。

## 规则维护

规则在 `lua/litewaf.lua` 的 `_M.RULES`（`-- RULES-BEGIN/END` 标记之间），格式为 `{ "类目", [[PCRE]] }`，类目：`sqli` / `xss` / `traversal` / `probe`（探测扫描，含工具 UA）。仓库用 `tests/litewaf_regex_test.php`（PHP PCRE 与 ngx.re 同源）做正负样本回归，修改规则后运行：

```bash
php LiteWAF/tests/litewaf_regex_test.php
lua5.1 LiteWAF/tests/litewaf_logic_test.lua   # CC/封禁/统计逻辑（stub ngx）
luac5.1 -p LiteWAF/lua/*.lua        # 语法检查
```

改动 Lua 文件后 reload 生效（reload 保留计数，重启清零）：

```bash
docker compose -f docker/compose.yaml exec nginx nginx -s reload
```

## 局限与注意事项

- 规则只看 URL / UA，请求体（即用户提交的日志内容）完全不做 WAF 检查——应用层脱敏由 Hyperf 的 Filter 体系负责。
- 若在 nginx 前再加 CDN / 负载均衡，`$binary_remote_addr` 将变成节点 IP，需先配置 nginx `real_ip` 模块并只信任已知代理，再谈 CC 防御的准确性。
- 统计页为公开端点，恶意方同样可以请求；它受 CC 限流保护，且不暴露任何可被利用的细节。
