-- LiteWAF — 极简 Nginx Lua WAF
-- CC 防御 + 常见攻击特征拦截（SQL 注入 / XSS / 路径穿越 / 探测扫描），
-- 触发时返回 403 警告页，并提供公开的极简安全统计页（/security）。
-- 文档与集成方式见 LiteWAF/README.md。

local _M = { _VERSION = "1.0.0" }

-- ───────────────────────── 配置 ─────────────────────────
local CONFIG = {
    -- shared dict 名称，须与 nginx.conf 中 lua_shared_dict 一致
    dict_name = "litewaf",
    -- 公开统计页前缀：跳过特征匹配（仍受 CC 限流），HTML 为前缀本身，JSON 为前缀 + /stats
    stats_prefix = "/security",
    -- 完全白名单前缀：跳过所有检查（ACME HTTP-01 等）
    whitelist_prefixes = {
        "/.well-known/acme-challenge/",
    },
    -- CC 防御：window 秒内超过 limit 次请求即封禁该 IP ban 秒。
    -- 注意：须低于 nginx limit_req 静态限速，否则超额请求会先被 limit_req 以 503 丢弃，轮不到封禁。
    cc = { window = 10, limit = 240, ban = 600 },
    -- 命中攻击特征后的封禁秒数
    sig_ban = 600,
    -- 警告页是否回显规则类目（便于误报定位；不希望向攻击者泄露规则覆盖面可设为 false）
    expose_rule = true,
}

-- ─────────── 攻击特征规则（PCRE，语法同 ngx.re）───────────
-- 按顺序匹配，命中即停。匹配对象依次为：原始 request_uri、解码后 uri、User-Agent。
-- RULES-BEGIN（tmp/ 下的 PHP 回归测试按此格式解析）
_M.RULES = {
    -- SQL 注入
    { "sqli",     [[union\s+(all\s+)?select]] },
    { "sqli",     [[\b(or|and)\s+\d+\s*=\s*\d+]] },
    { "sqli",     [['\s*(or|and)\s*']] },
    { "sqli",     [[\bsleep\s*\(\s*\d+]] },
    { "sqli",     [[\bbenchmark\s*\(\s*\d]] },
    { "sqli",     [[\bwaitfor\s+delay]] },
    { "sqli",     [[\bxp_cmdshell\b]] },
    { "sqli",     [[information_schema]] },
    { "sqli",     [[\bload_file\s*\(\s*']] },
    { "sqli",     [[into\s+(out|dump)file]] },
    { "sqli",     [[\b(drop|truncate)\s+table\b]] },
    { "sqli",     [[\bselect\s+[\w*,\s`'%]+\s+from\s+]] },
    -- XSS
    { "xss",      [[<\s*script\b]] },
    { "xss",      [[<\s*/\s*script]] },
    { "xss",      [[javascript\s*:]] },
    { "xss",      [[\bon(error|load|click|mouseover|focus|blur|input|submit)\s*=]] },
    { "xss",      [[<\s*(iframe|embed|object|svg)\b]] },
    { "xss",      [[document\s*\.\s*cookie]] },
    { "xss",      [[\balert\s*\(\s*\d*\)]] },
    -- 路径穿越（原始与解码形态均覆盖）
    { "traversal",[[(\.\./)+]] },
    { "traversal",[[\.\.\\]] },
    { "traversal",[[%2e%2e(%2f|%5c|/)]] },
    { "traversal",[[/etc/(passwd|shadow|hosts)]] },
    { "traversal",[[(boot|win)\.ini]] },
    -- 探测 / 扫描路径（本站无 PHP/JSP 等动态文件与运维后台，出现即视为探测）
    { "probe",    [[\.php(\?|$)]] },
    { "probe",    [[\.(asp|aspx|jsp|jspx)(\?|$)]] },
    { "probe",    [[/(\.env|\.git|\.svn|\.hg|\.DS_Store|\.htaccess)(/|\?|$)]] },
    { "probe",    [[/(\.aws|\.ssh|\.docker)(/|$)]] },
    { "probe",    [[phpmyadmin]] },
    { "probe",    [[wp-(admin|login|content|config|includes)]] },
    { "probe",    [[/actuator(/|\?|$)]] },
    { "probe",    [[/cgi-bin/]] },
    { "probe",    [[^/admin(\?|$)]] },
    { "probe",    [[/id_rsa(\?|$)]] },
    { "probe",    [[\.(sql|bak|backup|old|ini|conf|cfg|log|yml|yaml|json|xml)(\?|$)]] },
    { "probe",    [[/backup(/|\?|$)]] },
    -- 扫描器 / 攻击工具 User-Agent
    { "probe",    [[\b(sqlmap|nikto|nmap|masscan|zgrab|zmap|gobuster|dirbuster|dirb|wpscan|acunetix|netsparker|nessus|openvas|arachni|havij|sqlninja|wfuzz|ffuf|nuclei)\b]] },
}
-- RULES-END

-- ───────────────────── 警告页模板 ─────────────────────
local WARN_HTML = [[<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>403 请求已被拦截</title>
<style>
body{font-family:system-ui,-apple-system,"PingFang SC",sans-serif;max-width:560px;margin:14vh auto;padding:0 1.5rem;color:#374151;background:#fafafa;text-align:center}
h1{font-size:1.1rem;color:#1f2937}
p{font-size:.9rem;line-height:1.7;color:#6b7280}
code{font-size:.75rem;color:#9ca3af}
</style>
</head>
<body>
<h1>请求已被拦截</h1>
<p>你的请求触发了 LiteWAF 防护规则（{{REASON}}），已被拒绝访问。<br>如果你认为这是误判，请联系站点管理员并附上本页提示。</p>
<code>LiteWAF v{{VERSION}} · {{RULE}}</code>
</body>
</html>
]]

-- ───────────────────── 内部工具 ─────────────────────
local function dict() return ngx.shared[CONFIG.dict_name] end

local function bump(d, name)
    d:incr("c:" .. name, 1, 0)
end

local function starts_with(s, prefix)
    return prefix ~= "" and s ~= nil and s:sub(1, #prefix) == prefix
end

-- 模板替换工具：gsub 的 replacement 中 % 是转义符，统一转义后注入，
-- 防止动态值（如未来引入含 % 的 reason）破坏模板
local function tpl_replace(tpl, key, value)
    return tpl:gsub(key, (tostring(value):gsub("%%", "%%%%")))
end

local function whitelisted(uri)
    for _, p in ipairs(CONFIG.whitelist_prefixes) do
        if starts_with(uri, p) then return true end
    end
    return false
end

local function skip_signature(uri)
    -- 与 nginx location = 精确匹配语义一致；前缀匹配会让 /security<任意后缀>
    -- 绕过特征检查（S1），故仅豁免统计页自身的两个精确 URI
    return uri == CONFIG.stats_prefix
        or uri == CONFIG.stats_prefix .. "/stats"
end

-- 编译后的规则缓存（worker 级，惰性）：ngx.re.compile 不能在 init_by_lua（master 进程）
-- 阶段调用，必须在请求阶段首次用时编译；每个 worker 各自缓存一份。
local compiled_rules

local function get_rules()
    if not compiled_rules then
        compiled_rules = {}
        for i, rule in ipairs(_M.RULES) do
            local re, err = ngx.re.compile(rule[2], "ji")
            if not re then
                -- 规则非法：警告并降级到 ngx.re.find 字符串缓存路径（沿用 "ijo"）
                ngx.log(ngx.WARN, "[LiteWAF] invalid rule #" .. i .. " ("
                    .. rule[1] .. "): " .. tostring(err) .. "; fallback to string cache")
                compiled_rules = nil
                return _M.RULES
            end
            compiled_rules[i] = { rule[1], re }
        end
    end
    return compiled_rules
end

-- 对单个 subject 顺序匹配规则，返回命中类目或 nil。
-- 惰性编译优先走正则对象（减缓存查找），非法规则自动回退字符串缓存路径。
local function match_rules(subject)
    if not subject or subject == "" then return nil end
    local rules = get_rules()
    for _, rule in ipairs(rules) do
        local matcher = rule[2]
        local hit
        if type(matcher) == "table" then
            hit = matcher:find(subject)
        else
            hit = ngx.re.find(subject, matcher, "ijo")
        end
        if hit then return rule[1] end
    end
    return nil
end

local function deny(d, category, with_reason)
    bump(d, "blocked")
    if category then bump(d, category) end
    -- 服务端审计日志（含 IP，仅入 nginx error log，不影响统计页隐私约定）
    ngx.log(ngx.WARN, "[LiteWAF] block ip=", ngx.var.binary_remote_addr or "?",
        " rule=", category or "ban", " uri=", ngx.var.uri or "-")
    local reason = with_reason
        and (category == "cc" and "CC 请求频率超限" or "恶意攻击特征")
        or "访问已被临时封禁"
    local page = tpl_replace(WARN_HTML, "{{REASON}}", reason)
    page = tpl_replace(page, "{{VERSION}}", _M._VERSION)
    page = tpl_replace(page, "{{RULE}}",
        CONFIG.expose_rule and (category or "ban") or "-")
    ngx.status = 403
    ngx.header.content_type = "text/html; charset=utf-8"
    ngx.say(page)
    -- 官方推荐写法：状态码与响应体已发送后，用 ngx.exit(ngx.HTTP_OK) 结束整个请求，
    -- 避免 nginx 默认错误页覆盖自定义警告页。
    return ngx.exit(ngx.HTTP_OK)
end

-- ───────────────────── 生命周期 ─────────────────────
-- init_by_lua：master 进程初始化，仅记录启动时间（shared dict 全 worker 共享）。
-- 注意：此处不能做规则预编译——ngx.re.compile 只存在于请求阶段（worker 上下文），
-- init 阶段调用会报 "attempt to call field 'compile' (a nil value)"；
-- 预编译由 get_rules() 在首个请求时惰性完成（每 worker 一次）。
function _M.init()
    local d = dict()
    if d and not d:get("c:start_epoch") then
        d:set("c:start_epoch", ngx.now())
    end
end

-- access_by_lua：每个请求的检查入口
function _M.access()
    local d = dict()
    if not d then return end  -- shared dict 未配置时直接放行，不阻塞业务

    local uri = ngx.var.uri or "/"

    -- 完全白名单：不计入、不检查
    if whitelisted(uri) then return end
    bump(d, "total")

    local ip = ngx.var.binary_remote_addr or "unknown"

    -- 封禁名单检查（CC 与特征命中共用）
    if d:get("b:" .. ip) then
        return deny(d, nil, false)
    end

    -- CC 防御：固定窗口计数（键按窗口分桶，TTL 两倍窗口自动回收）
    local window = CONFIG.cc.window
    local bucket = math.floor(ngx.now() / window)
    local key = "r:" .. ip .. ":" .. bucket
    local n = d:incr(key, 1, 0)
    if n == 1 then
        d:expire(key, window * 2)
    end
    if n and n > CONFIG.cc.limit then
        d:set("b:" .. ip, 1, CONFIG.cc.ban)
        return deny(d, "cc", true)
    end

    -- 统计页自身：跳过特征匹配，避免规则误伤公开页
    if skip_signature(uri) then return end

    -- 特征匹配：原始 request_uri（含原始编码）→ 解码后 uri → User-Agent
    local subjects = { ngx.var.request_uri, uri, ngx.var.http_user_agent }
    for _, subject in ipairs(subjects) do
        local category = match_rules(subject)
        if category then
            d:set("b:" .. ip, 1, CONFIG.sig_ban)
            return deny(d, category, true)
        end
    end
end

-- ───────────────────── 公开统计页 ─────────────────────
local STATS_HTML = [[<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="60">
<title>LiteWAF 安全统计</title>
<style>
body{font-family:system-ui,-apple-system,"PingFang SC",sans-serif;max-width:640px;margin:3rem auto;padding:0 1rem;color:#1f2937;background:#fafafa}
h1{font-size:1.2rem}
table{border-collapse:collapse;width:100%;background:#fff;border:1px solid #e5e7eb}
th,td{padding:.5rem .75rem;text-align:left;border-bottom:1px solid #f3f4f6;font-size:.9rem}
th{color:#6b7280;font-weight:600}
td.num{text-align:right;font-variant-numeric:tabular-nums}
footer{color:#9ca3af;font-size:.75rem;margin-top:1rem;line-height:1.6}
</style>
</head>
<body>
<h1>LiteWAF 安全统计</h1>
<table>
<tr><th>指标</th><th class="num">数值</th></tr>
{{ROWS}}
</table>
<footer>LiteWAF v{{VERSION}} · 运行 {{UPTIME}} 秒 · 计数保存在内存中，进程重启后清零 · 每 60 秒自动刷新</footer>
</body>
</html>
]]

function _M.stats()
    local d = dict()
    local uri = ngx.var.uri or CONFIG.stats_prefix
    local started = (d and d:get("c:start_epoch")) or ngx.now()
    local function c(name) return (d and d:get("c:" .. name)) or 0 end

    local data = {
        name = "LiteWAF",
        version = _M._VERSION,
        uptime_seconds = math.floor(ngx.now() - started),
        requests_total = c("total"),
        blocked_total = c("blocked"),
        blocked = {
            cc = c("cc"),
            sqli = c("sqli"),
            xss = c("xss"),
            traversal = c("traversal"),
            probe = c("probe"),
        },
    }

    -- JSON 输出：/security/stats
    if uri == CONFIG.stats_prefix .. "/stats" then
        local cjson = require "cjson.safe"
        ngx.header.content_type = "application/json; charset=utf-8"
        ngx.header["Cache-Control"] = "no-store"
        -- cjson.safe 失败返回 nil（而非抛错），兜底空 JSON 保证端点可用
        ngx.say(cjson.encode(data) or "{}")
        return
    end

    -- HTML 输出：/security
    local labels = {
        { "累计请求", data.requests_total },
        { "已拦截请求", data.blocked_total },
        { "其中：CC 频率超限", data.blocked.cc },
        { "其中：SQL 注入特征", data.blocked.sqli },
        { "其中：XSS 特征", data.blocked.xss },
        { "其中：路径穿越", data.blocked.traversal },
        { "其中：探测 / 扫描", data.blocked.probe },
    }
    local rows = {}
    for _, row in ipairs(labels) do
        rows[#rows + 1] = string.format(
            '<tr><td>%s</td><td class="num">%d</td></tr>',
            row[1], row[2])
    end
    local html = tpl_replace(STATS_HTML, "{{ROWS}}", table.concat(rows, "\n"))
    html = tpl_replace(html, "{{VERSION}}", _M._VERSION)
    html = tpl_replace(html, "{{UPTIME}}", data.uptime_seconds)
    ngx.header.content_type = "text/html; charset=utf-8"
    ngx.say(html)
end

return _M
