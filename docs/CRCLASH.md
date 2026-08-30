# LiteWAF 专项 Code Review 报告

## 概览

- **审查日期：** 2026-08-29
- **审查重点：** 应用户要求，本轮聚焦 `LiteWAF/`（独立 OpenResty-Lua WAF 项目）及其 Docker 集成；此前 2026-08-27 的全仓库报告已被本报告替换，可在 git 历史（提交 `9129bd4`）中找回。
- **审查范围：** `LiteWAF/`（`lua/litewaf.lua` 282 行、`lua/waf.lua`、`lua/stats.lua`、`nginx/nginx.conf`、`README.md`）+ 集成面（`docker/compose.yaml`、`docker/nginx/default.conf`、`.dockerignore`）+ 回归测试（`tmp/litewaf_regex_test.php`、`tmp/litewaf_logic_test.lua`）+ 文档同步（`AGENTS.md`、`README.md`），共 12 个文件。
- **排除目录：** `.git/`、`vendor/`、`runtime/` 等依赖与生成物；`app/` 等主项目代码本轮未变更，沿用上一轮结论。
- **技术栈识别：** Lua 5.1（LuaJIT，运行于 OpenResty 1.27.1.2 + lua-nginx-module）；无第三方 Lua 依赖（仅内置 `cjson.safe`）；部署经 Docker Compose，镜像 tag 已固定；测试用 PHP 8（PCRE 与 ngx.re 同源）与纯 Lua 5.1 stub，无独立测试框架。
- **整体评价：** 设计克制、边界清晰，安全取舍（不查请求体、不公开 IP、CC 阈值低于 limit_req）有明确论证且文档同步；回归测试覆盖了规则正误与核心逻辑。存在 1 个可实际利用的检查绕过缺陷（S1）、3 个运维/健壮性问题（G1-G3）与 10 条改进建议。无第三方依赖供应链风险。

## 修复记录（2026-08-29）

经确认，**S1 与 G1-G3、W1-W10 已全部修复或闭环**，并通过回归验证（Lua 语法检查、正则 43 样本、逻辑 26 项全部通过）：

- **S1**：`skip_signature` 改为与 nginx `location =` 一致的精确匹配（仅豁免 `/security` 与 `/security/stats`），并新增 T13-T15 逻辑用例与 5 条正则样本防止回归。
- **G1**：`deny` 增加 `ngx.log(ngx.WARN, ...)` 审计日志（IP、规则、URI 入 nginx error log）。
- **G2**：两份回归测试迁移至 `LiteWAF/tests/` 并纳入版本控制，`AGENTS.md` 与 `LiteWAF/README.md` 引用同步更新。
- **G3**：JSON 统计页增加 `cjson.encode` nil 兜底。
- **W1**：nginx 服务增加 healthcheck（`wget --spider --no-check-certificate https://127.0.0.1/security`，Lua 直接响应、不依赖 hyperf）。
- **W2/W3**：固定窗口双倍突发与短脉冲 503 兜底两项边界已写入 `LiteWAF/README.md`「已知边界」，按文档取舍闭环，未增加第二阈值。
- **W4**：规则编译改为 worker 级惰性预编译（首个请求触发 `ngx.re.compile` 并缓存）。**注意**：此前的"init 阶段预编译"方案在真实 OpenResty 中不可行——`ngx.re.compile` 仅在请求阶段可用，`init_by_lua`（master 进程）调用会报 `attempt to call field 'compile' (a nil value)`；该问题由 CI Docker 冒烟测试暴露并已修正，非法规则自动降级回 `ngx.re.find` 字符串缓存路径。
- **W5**：新增 `CONFIG.expose_rule` 开关控制警告页是否回显规则类目。
- **W6**：统计页响应增加 `Cache-Control: no-store`。
- **W7**：`location = /security/` 301 跳转到统计页。
- **W8**：镜像 tag 升级核对要点写入 `LiteWAF/README.md` 集成章节。
- **W9**：新增 `tpl_replace` 模板替换工具，统一转义 `%`，警告页与统计页共用。
- **W10**：逻辑测试补「封禁到期自动解封」用例（T15，mock dict 支持 TTL 惰性过期）。

至此本报告 14 项发现全部闭环，无遗留待办。


## 问题清单

### 严重

#### S1. 统计页前缀匹配过宽，`/security<任意后缀>` 可绕过全部特征规则

- **位置：** `LiteWAF/lua/litewaf.lua:114-116`（`skip_signature`），配合 `docker/nginx/default.conf:37-43`。
- **问题：** `skip_signature` 用前缀匹配 `starts_with(uri, "/security")`，而 nginx 侧统计页是 `location = /security` 与 `location = /security/stats` 精确匹配。两者不一致导致：请求 `/security/xxx` 或 `/securityXXX` 时，nginx 将其送入 `location /` 代理到 Hyperf，但 WAF 因前缀命中而**跳过所有特征规则**（CC 限流仍生效）。攻击者只需给恶意 URI 加上 `/security` 前缀即可让 SQL 注入、XSS、路径穿越特征全部失效，直达后端。
- **证据：** `location = /security` 是精确匹配（不匹配子路径）；`/security/../x` 会被 nginx 归一化为 `/x`，同样落入 `location /`。当前唯一被正确豁免的 URI 恰恰只有统计页本身。
- **建议：** 将 `skip_signature` 改为与 nginx `location =` 语义一致的精确匹配：

  ```lua
  local function skip_signature(uri)
      return uri == CONFIG.stats_prefix
          or uri == CONFIG.stats_prefix .. "/stats"
  end
  ```

  修复后需在 `tmp/litewaf_logic_test.lua` 补一条"`/security/` 前缀变体仍走特征检查"的用例。

### 一般

#### G1. 拦截事件无任何日志，封禁不可审计

- **位置：** `LiteWAF/lua/litewaf.lua:127-143`（`deny`）及封禁写入点（`:181`、`:193`）。
- **问题：** 触发拦截只累加内存计数器，不写 `ngx.log`，nginx error log 中看不到被谁、因哪条规则被拦。计数器重启清零且无 IP 维度，事后无法做误报申诉定位、攻击溯源和规则调优——对一个 WAF 而言这是运维能力的关键缺口。
- **建议：** 在 `deny` 中加 `ngx.log(ngx.WARN, "[LiteWAF] block ip=", ngx.var.binary_remote_addr, " rule=", category or "ban", " uri=", ngx.var.uri)`；日志中已含 IP，属服务端日志，不违反统计页"不公开 IP"的隐私约定。

#### G2. 回归测试放在 gitignored 的 `tmp/`，不会随仓库分发

- **位置：** `tmp/litewaf_regex_test.php`、`tmp/litewaf_logic_test.lua`；`.gitignore` 含 `/tmp/`。
- **问题：** 两份测试是 LiteWAF 的质量保障核心（规则正误样本 + 逻辑用例），但 `tmp/` 被全局忽略，新克隆的仓库中不存在；`AGENTS.md` 与 `LiteWAF/README.md` 引用的回归手段随之失效，规则修改后无法在别处验证。
- **建议：** 迁移到 `LiteWAF/tests/litewaf_regex_test.php` 与 `LiteWAF/tests/litewaf_logic_test.lua`，同步更新两处文档引用；测试路径解析改为相对测试文件自身定位 `../lua/litewaf.lua`。

#### G3. `cjson.safe.encode` 失败返回 nil，统计页 JSON 端点会 500

- **位置：** `LiteWAF/lua/litewaf.lua:250-253`。
- **问题：** `cjson.safe` 在编码失败时返回 `nil`（而非抛错），`ngx.say(nil)` 会抛 Lua 异常，使 `/security/stats` 返回 500。当前 `data` 结构简单、触发概率极低，但统计页恰是攻击期间最需要可用的端点，容错应为零成本。
- **建议：** `local json = cjson.encode(data) or "{}"` 后再 `ngx.say`。

### 建议

#### W1. nginx 容器缺少 healthcheck

- **位置：** `docker/compose.yaml`（nginx 服务）。
- **问题：** hyperf / mariadb / redis 均配置了 healthcheck，唯 nginx 没有。配置挂载错误（如 LiteWAF lua 路径变更）导致 nginx 启动失败或空转时，编排层无法感知。
- **建议：** 增加 `test: ["CMD", "wget", "-q", "--spider", "http://127.0.0.1:80/"]`（alpine 内置 busybox wget；80 端口始终监听，301/403 均为存活信号）。

#### W2. CC 固定窗口存在跨窗口双倍突发

- **位置：** `LiteWAF/lua/litewaf.lua:172-183`。
- **问题：** 固定窗口算法在窗口交界处，一分钟内理论可通过约 2×limit 的请求（前一窗口尾 + 后一窗口头），这是固定窗口的固有特性，非实现错误。
- **建议：** 保持现状，在 `LiteWAF/README.md` 的"能力与取舍"中补一句说明；若未来需要更平滑限流，可用 shared dict 实现双窗口或令牌桶。

#### W3. 短时高频突发由 nginx limit_req 以 503 兜底，LiteWAF 无法封禁

- **位置：** `docker/nginx/default.conf:46`（`limit_req burst=60 nodelay`）与 `LiteWAF/lua/litewaf.lua:20`。
- **问题：** 瞬时超过 burst 的请求在 PREACCESS 阶段即被 503 丢弃，到不了 ACCESS 阶段的 LiteWAF，短脉冲型攻击只产生 503 风暴而不会触发封禁与计数。两阶段协作边界已在文档中论证，此处仅提示增强选项。
- **建议：** 如需覆盖短脉冲，可增加 1 秒级细粒度窗口（如 80 次/秒）作为第二道 CC 阈值；或接受现状（503 对攻击者同样有效且零成本）。

#### W4. 正则未在 init 阶段预编译

- **位置：** `LiteWAF/lua/litewaf.lua:119-125`（`match_rules`）。
- **问题：** `ngx.re.find(subject, pattern, "ijo")` 的 `o` 标志命中 OpenResty 全局编译缓存，稳态开销可接受；但每 worker 冷启动有一次编译成本，且每次调用经过缓存查找与参数解析。
- **建议：** 在 `init_by_lua` 中用 `ngx.re.compile` 把 `_M.RULES` 预编译为正则对象数组（对象自带 `:find`）。可选微优化，非必需。

#### W5. 警告页向攻击者回显规则类目

- **位置：** `LiteWAF/lua/litewaf.lua:136`（`{{RULE}}` 输出 `sqli` / `probe` 等）。
- **问题：** 回显类目便于误报定位，但也让攻击者能低成本探测规则覆盖范围。
- **建议：** 可保留（信息量很低），或加配置项 `expose_rule` 控制输出；配合 G1 的服务端日志，运维仍可事后审计。

#### W6. 统计页响应缺少 `Cache-Control: no-store`

- **位置：** `LiteWAF/lua/litewaf.lua:252`、`:277`。
- **问题：** 页面声明 60 秒自动刷新，但未显式禁止中间层缓存；未来若链路加入代理缓存，可能展示过期计数。
- **建议：** 两处 `content_type` 赋值旁追加 `ngx.header["Cache-Control"] = "no-store"`。

#### W7. `/security/`（带尾斜杠）落入 Hyperf 返回 API 风格 404

- **位置：** `docker/nginx/default.conf:37-43`（`location =` 精确匹配）。
- **问题：** 用户手输 `/security/` 不会命中统计页，而是代理到 Hyperf 得到 JSON 404，体验不一致（修复 S1 时需保持两处语义一致）。
- **建议：** 增加 `location = /security/ { return 301 /security; }`；纯体验问题，优先级低。

#### W8. 镜像 tag 未跟进 OpenResty 补丁线

- **位置：** `docker/compose.yaml:5`（`openresty/openresty:1.27.1.2-alpine`）。
- **问题：** 版本固定符合项目约定，但上游存在 `-7` 补丁系列（含安全修复），固定 tag 需人工关注升级。
- **建议：** 周期性检查上游 tag；升级时按 compose 注释提示，同步核对 `LiteWAF/nginx/nginx.conf` 与镜像内置配置差异。

#### W9. `gsub` 模板替换对 `%` 字符不设防

- **位置：** `LiteWAF/lua/litewaf.lua:133-136`、`:273-276`。
- **问题：** Lua `string.gsub` 的 replacement 中 `%` 是转义符。当前所有替换值均为常量（无 `%`），安全；但若日后 reason/category 引入含 `%` 的动态内容会产生错误输出。
- **建议：** 保持常量约定并加注释；或封装 `_replace(tpl, k, v)` 工具，内部对 `v` 做 `v:gsub("%%", "%%%%")`。

#### W10. 逻辑测试未覆盖封禁 TTL 到期路径

- **位置：** `tmp/litewaf_logic_test.lua`（T5-T9）。
- **问题：** mock dict 未模拟 TTL 过期，"封禁到期自动解封"（`:181`、`:193` 的 `d:set(key, 1, ban)` TTL）路径无测试。生产由 shared dict TTL 保证，风险低，但属覆盖缺口。
- **建议：** mock dict 的 `get` 依据 `ex` 与当前 NOW 判断过期，补一条"封禁到期后恢复放行"用例。

## 改进建议（按优先级汇总）

1. **立即修复 S1**：`skip_signature` 改精确匹配（一行改动 + 一条测试），消除特征检查绕过。
2. **短期落地 G1-G3**：给 `deny` 加 `ngx.log(ngx.WARN, ...)`；测试迁移到 `LiteWAF/tests/` 并更新 `AGENTS.md`、`LiteWAF/README.md` 引用；JSON 编码加 nil 兜底。
3. **择机实施 W1、W6、W7**：nginx healthcheck、`Cache-Control`、尾斜杠跳转，均为数行改动。
4. **文档补充 W2/W3**：在 LiteWAF README 的取舍清单中写明固定窗口双倍突发与 503 兜底的边界，避免后续维护者误判。
5. **长期可选**：W4 正则预编译、W5 规则回显开关、W10 TTL 用例、双窗口限流升级。

## 正面亮点

- **零第三方依赖**：仅用 OpenResty 内置能力（shared dict、ngx.re、cjson），无 LuaRocks 依赖，供应链面积极小；镜像版本已固定。
- **失败语义明确**：`init_by_lua` 阶段模块加载失败会拒绝启动（fail-closed）；运行期 shared dict 缺失则放行不阻塞业务（fail-open），两种语义的选择都有意识且注释清晰。
- **无内存泄漏路径**：CC 计数键按窗口分桶并设 2×窗口 TTL 自动回收，封禁键自带 TTL，计数器数量有界；`incr` 原子操作保证多 worker 一致。
- **规则工程化**：37 条规则带机器可读标记（`-- RULES-BEGIN/END`），配 38 个正负样本的 PHP PCRE 回归（与 ngx.re 同源）+ 19 项 stub ngx 逻辑测试，规则变更可验证。
- **安全取舍有论证**：不检查请求体（应用层日志天然含攻击载荷，避免误报）、统计页不公开 IP、CC 阈值须低于 nginx limit_req 的阶段关系分析，均已写入 LiteWAF README 与 AGENTS.md。
- **隐私与信息泄露控制**：统计页只暴露聚合计数；警告页不含栈信息与内部路径；443 server 的 `X-Content-Type-Options`、`server_tokens off` 对 Lua 生成的响应同样生效。
- **边界整洁**：LiteWAF 以独立目录存在，只读挂载（`:ro`）进 nginx 容器，不进入应用镜像（`.dockerignore` 已排除）；`waf.lua`/`stats.lua` 薄入口 + 单模块核心，职责划分简单清楚。
- **文档同步到位**：`AGENTS.md`、主 `README.md`、`LiteWAF/README.md` 三处对 WAF 的描述与实现一致，包括阈值调优要点与上线验证命令。

