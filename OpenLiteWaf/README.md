# OpenLiteWaf

运行在 nginx 容器内的 OpenResty Lua WAF。在反向代理层对请求做 CC 限速与攻击特征检查，并维护一个公开的统计页。它工作在 nginx access 阶段，所有状态保存在 `lua_shared_dict` 中，不写盘，进程重启后清零。许可证：MIT。

代码结构：

```
OpenLiteWaf/
├── lua/
│   ├── openlitewaf.lua   # 核心模块：配置、规则、检查逻辑、统计输出
│   ├── waf.lua           # access_by_lua_file 入口，调用 openlitewaf.access()
│   └── stats.lua         # content_by_lua_file 入口，调用 openlitewaf.stats()
├── nginx/nginx.conf      # OpenResty 主配置（lua_package_path、shared dict、init）
└── tests/                # 回归测试，见"规则维护"
```

## 工作方式

每个请求进入 access 阶段后按固定顺序检查，任一步命中即终止。ACME 白名单路径（`/.well-known/acme-challenge/`）直接放行；已在封禁名单中的 IP 返回 403；CC 计数超过窗口阈值时封禁该 IP 并返回 403；统计页自身的三个 URI（`/security`、`/security/stats`、`/security/logs`）跳过特征检查；其余请求进入特征匹配。

特征匹配的对象依次为：原始 `request_uri`（保留编码形态，用于捕获 `%2e%2e%2f` 一类编码特征）、规范化路径、`User-Agent`、完整 URL 解码后的 `request_uri`（含 query）、请求体、请求体的一次 URL 解码。URL 解码只做一层：两层编码能被解码后的检查对象覆盖，三层编码则绕过。规则表按定义顺序匹配，命中即停，命中的类目决定计数归属与警告页显示的类目。

请求体检查只对 POST/PUT/PATCH 生效，且要求 `Content-Length` 不超过 2MB，扫描范围为前 64KB——body 超出 nginx `client_body_buffer_size` 落盘时读文件头部。payload 置于 64KB 之后或拆入超限请求可以绕过，这是内存与 IO 成本上的取舍。日志内容类端点（见 `body_exempt_prefixes`，默认豁免 `/v1/log`、`/1/log`）整体豁免 body 检查：日志原文中出现攻击特征属于正常业务，做扫描会误伤；若新增接收任意文本的端点，应将其加入该列表。

命中特征后，模块封禁该 IP（默认 600 秒）、按类目计数、写入攻击日志与分钟趋势，并返回 403 页面（卡片组件，无页面外壳，支持 iframe 嵌入；拦截原因仅写入 nginx error log，不在页面上展示）。

## 统计页

`GET /security` 返回 HTML 页面，由内联 JS 渲染并每 30 秒轮询刷新，需要浏览器启用 JavaScript。数据接口有两个 JSON 端点：

`GET /security/stats` 返回汇总：累计请求与拦截数（`requests_total`、`blocked_total`）、按类目分布（`blocked`，含 `cc`、`sqli`、`xss`、`traversal`、`rce`、`probe`）、累计封禁次数（`banned_total`）、当前封禁 IP 数（`banned_active`）、缓存日志条数（`logs_total`）、最近 60 分钟逐分钟拦截数（`trends`）与来源 IP Top（`top_ips`，按最近日志聚合）。

`GET /security/logs?page=N` 返回攻击日志分页，每页 50 条，最新在前，页码越界时收敛到边界页。每条记录包含时间（`t`）、类目（`cat`）、脱敏 IP（`ip`）、URI（`u`）与 User-Agent（`a`）。

公开页面的隐私处理：IP 一律脱敏（IPv4 保留前两段，IPv6 保留前三组），URI 中的 `token=` 参数值替换为 `***`，完整 IP 只出现在 nginx error log 的拦截记录中。统计页是公开端点，攻击者同样可以访问，它受 CC 限流保护。

数据以环形槽位保存在 shared dict：攻击日志 500 条、封禁槽位 1024 个，写满后覆盖最旧记录。shared dict 不支持枚举键，`banned_active` 通过遍历封禁槽位统计未到期数量得出，同一 IP 重复封禁会覆盖槽位，结果可能低估。

## 部署

nginx 服务使用 OpenResty 镜像（当前适配 `openresty/openresty:1.27.1.2-alpine`），相关挂载：

```yaml
volumes:
  - ./nginx:/etc/nginx/conf.d:ro                                    # 站点配置
  - ../OpenLiteWaf/nginx/nginx.conf:/usr/local/openresty/nginx/conf/nginx.conf:ro
  - ../OpenLiteWaf/lua:/usr/local/openresty/nginx/lua:ro
```

站点配置的 80 与 443 两个 server 都需要 `access_by_lua_file`，443 另有三个统计 location：

```nginx
access_by_lua_file /usr/local/openresty/nginx/lua/waf.lua;
location = /security       { content_by_lua_file /usr/local/openresty/nginx/lua/stats.lua; }
location = /security/stats { content_by_lua_file /usr/local/openresty/nginx/lua/stats.lua; }
location = /security/logs  { content_by_lua_file /usr/local/openresty/nginx/lua/stats.lua; }
```

独立部署时需在 http 级提供 `lua_package_path`、`lua_shared_dict openlitewaf 16m` 与 `init_by_lua_block`（见 `nginx/nginx.conf`），再挂接上述 location。

改动 Lua 文件后需要让 OpenResty 重新加载：`nginx -s reload` 或容器 `restart`。OpenResty 默认开启 `lua_code_cache`，`git pull` 只更新磁盘文件，不重载则 worker 仍运行旧代码。reload 保留计数，重启清零。

升级 OpenResty 镜像 tag 时，核对 `nginx/nginx.conf` 与镜像内置配置的差异（lua 指令、临时路径、默认 include），必要时重新挂载。

## 配置

全部配置集中在 `lua/openlitewaf.lua` 顶部的 `CONFIG` 表：

| 配置 | 默认值 | 说明 |
| --- | --- | --- |
| `cc.window` / `cc.limit` | 10 / 240 | CC 窗口秒数 / 窗口内请求上限 |
| `cc.ban` | 600 | CC 封禁秒数 |
| `sig_ban` | 600 | 命中特征后的封禁秒数 |
| `whitelist_prefixes` | `/.well-known/acme-challenge/` | 完全白名单，不计数不检查 |
| `body_exempt_prefixes` | `/v1/log`、`/1/log` | 请求体检查豁免前缀 |
| `body_scan_limit` / `body_size_limit` | 65536 / 2MB | body 扫描前 N 字节 / 超过该 Content-Length 跳过 |
| `log_capacity` / `log_page_size` | 500 / 50 | 攻击日志环形容量 / 每页条数 |
| `ban_slots` | 1024 | 封禁槽位数 |
| `trend_minutes` | 60 | 趋势图统计的分钟数 |
| `top_ips` | 8 | 统计页展示的来源 IP Top 数 |
| `log_field_max` | 160 | 攻击日志单字段（URI / UA）最大长度 |
| `stats_prefix` | `/security` | 统计页前缀 |
| `dict_name` | `openlitewaf` | 须与 `lua_shared_dict` 名一致 |

CC 阈值与 nginx 静态限速有联动关系：`limit_req`（PREACCESS 阶段）先于本模块（ACCESS 阶段）执行，超额请求以 503 丢弃、不进入计数，因此 `cc.limit` 折算（`limit / window`）必须低于 `limit_req` 速率，否则 CC 封禁不会触发。调整任一侧时检查另一侧。短时高频脉冲会被 `limit_req` 直接丢弃，不会计入本模块。

## 规则

规则定义在 `lua/openlitewaf.lua` 的 `_M.RULES`，位于 `-- RULES-BEGIN` / `-- RULES-END` 标记之间，每条为 `{ "类目", [[PCRE]] }`，类目为小写字母：`sqli`、`xss`、`traversal`、`rce`、`probe`。正则使用 ngx.re 语法，编译 flags 为 `ji`（忽略大小写 + JIT）。

编写时注意：规则必须保持单行双元素表，`tests/openlitewaf_regex_test.php` 按 `RULES-BEGIN/END` 标记与该格式解析；规则内容含 `]]` 时（如字符类 `[a-z]` 结尾），Lua 长括号改用 `[==[ ]==]`；规则按顺序匹配、命中即停，新增规则的位置影响计数归属；修改或新增规则时，同步在 `tests/openlitewaf_regex_test.php` 补充正样本（应拦截）与反样本（不应拦截）。

回归测试与语法检查：

```bash
php OpenLiteWaf/tests/openlitewaf_regex_test.php    # 规则正负样本（PHP PCRE，与 ngx.re 同源）
lua5.1 OpenLiteWaf/tests/openlitewaf_logic_test.lua # 检查流程/CC/体检查/日志/统计逻辑（stub ngx）
luac5.1 -p OpenLiteWaf/lua/*.lua                    # 语法检查
```

## 注意事项

规则只覆盖已知特征，语义层攻击与定向利用不在防护范围。限速与日志按 IP 维度统计，NAT 与共享出口会造成误伤；nginx 前存在 CDN 或负载均衡时，`remote_addr` 是节点 IP，需先配置 nginx `real_ip` 模块并只信任已知代理，否则 CC 计数与日志 IP 均不准确。本模块无 IP 信誉库、无 JS 挑战、无 GeoIP 定位（趋势图为本站数据），高强度抗 CC 依赖前置 CDN/WAF。

## 许可证

MIT，见 [LICENSE](LICENSE)。
