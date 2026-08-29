-- LiteWAF 逻辑回归测试（Lua 5.1，stub ngx 运行真实模块代码）
-- 用法：lua5.1 LiteWAF/tests/litewaf_logic_test.lua
-- 覆盖：白名单、CC 窗口与封禁、封禁 TTL 到期解封、特征命中与 S1 前缀绕过防护、
-- 统计页跳过语义、计数器、统计页输出。

local fail = 0
local function ok(cond, name)
    if cond then
        print("PASS  " .. name)
    else
        fail = fail + 1
        print("FAIL  " .. name)
    end
end

-- ── 共享 dict mock（支持 TTL 惰性过期）──
local NOW = 1000000.0
local function newdict()
    local d = { store = {} }
    function d:_live(k)
        local e = self.store[k]
        if e and e.exp and NOW >= e.exp then
            self.store[k] = nil
            return nil
        end
        return e
    end
    function d:get(k)
        local e = self:_live(k)
        return e and e.v or nil
    end
    function d:set(k, v, ex)
        self.store[k] = { v = v, exp = ex and (NOW + ex) or nil }
        return true
    end
    function d:incr(k, delta, init)
        local e = self:_live(k)
        if not e then
            e = { v = init or 0, exp = nil }
            self.store[k] = e
        end
        e.v = e.v + delta
        return e.v
    end
    function d:expire(k, t)
        local e = self:_live(k)
        if e then
            e.exp = NOW + t
            return true
        end
        return false
    end
    return d
end

-- ── ngx mock ──
local RE_FIND = nil   -- 非 nil 时模拟“规则命中”
local EXITED = nil
local SAID = nil

ngx = {
    now = function() return NOW end,
    re = {
        find = function(_, s, p, f) return RE_FIND and 1 or nil end,
        -- 模拟 ngx.re.compile（W4 预编译路径），编译行为受同一 RE_FIND 开关控制
        compile = function(_, pat, flags)
            return { find = function(_, s) return RE_FIND and 1 or nil end }, nil
        end,
    },
    shared = { litewaf = newdict() },
    var = {},
    header = {},
    status = nil,
    HTTP_OK = 200,
    WARN = 4,
    log = function() end,
    say = function(body) SAID = body end,
    exit = function(code) EXITED = code; error({ exit = code }) end,
}

-- cjson.safe stub（仅平铺/一层嵌套，足够统计页 JSON）；写入 package.loaded 使 require 直接命中
package.loaded["cjson.safe"] = {
    encode = function(v)
        if type(v) ~= "table" then return tostring(v) end
        local parts = {}
        for k, val in pairs(v) do
            local out
            if type(val) == "table" then out = package.loaded["cjson.safe"].encode(val)
            elseif type(val) == "number" then out = string.format("%.0f", val)
            else out = string.format('"%s"', tostring(val)) end
            parts[#parts + 1] = string.format('"%s":%s', k, out)
        end
        return "{" .. table.concat(parts, ",") .. "}"
    end,
}

local waf = dofile(debug.getinfo(1, "S").source:gsub("^@", ""):gsub("[^/]+$", "") .. "../lua/litewaf.lua")

-- ── 请求模拟 ──
local function request(opts)
    ngx.var.uri = opts.uri or "/"
    ngx.var.request_uri = opts.request_uri or ngx.var.uri
    ngx.var.binary_remote_addr = opts.ip or "1.2.3.4"
    ngx.var.http_user_agent = opts.ua or "Mozilla/5.0"
    ngx.status = nil
    SAID = nil
    EXITED = nil
    RE_FIND = opts.hit and 1 or nil
    local blocked
    local okrun, err = pcall(function() waf.access() end)
    if okrun then
        blocked = false
    else
        -- ngx.exit 经 error 表抛出以模拟中断
        blocked = (type(err) == "table" and err.exit ~= nil) or false
    end
    return {
        blocked = blocked,
        status = ngx.status,
        body = SAID,
        dict = ngx.shared.litewaf,
    }
end

local function counter(d, name)
    return (d:get("c:" .. name)) or 0
end

-- T1 init 记录启动时间
waf.init()
ok(ngx.shared.litewaf:get("c:start_epoch") == NOW, "init 记录启动时间")

-- 惰性编译：init 阶段不持有编译结果（ngx.re.compile 在 init 阶段不可用），
-- 首次请求走 get_rules() 完成 worker 级编译；匹配行为由后续用例覆盖。
ok(waf._compiled == nil, "init 阶段不做规则预编译（惰性编译在首请求）")

-- T2 白名单路径：不计数、不检查、不拦截
local r = request({ uri = "/.well-known/acme-challenge/tok123", hit = true })
ok(not r.blocked, "白名单（ACME）不拦截")
ok(counter(r.dict, "total") == 0, "白名单不计入统计")

-- T3 普通请求放行并计数
r = request({ uri = "/v1/log" })
ok(not r.blocked, "普通请求放行")
ok(counter(r.dict, "total") == 1, "普通请求计入 total")

-- T4 统计页：跳过特征匹配（即使规则命中）但计入 total
r = request({ uri = "/security", hit = true })
ok(not r.blocked, "统计页跳过特征匹配")

-- T5 CC：第 limit+1 次触发封禁（窗口内）
local d
for i = 1, 241 do
    d = request({ uri = "/v1/log", ip = "5.6.7.8" })
end
ok(d.blocked and d.status == 403, "CC 超限返回 403")
ok(d.body and d.body:find("请求已被拦截", 1, true) ~= nil, "CC 拦截返回警告页")
ok(counter(d.dict, "cc") == 1, "CC 触发计入 cc 计数")

-- T6 封禁期间即使低频也拦截（不计类目，只计 blocked）
r = request({ uri = "/v1/log", ip = "5.6.7.8" })
ok(r.blocked, "封禁期内持续拦截")
ok(counter(r.dict, "cc") == 1, "封禁期不再重复计类目")

-- T7 窗口滑动：新窗口恢复计数（换未封禁 IP）
NOW = NOW + 11
r = request({ uri = "/v1/log", ip = "9.9.9.9" })
ok(not r.blocked, "新窗口内正常放行")

-- T8 特征命中：封禁 + 类目计数
NOW = NOW + 1
r = request({ uri = "/?id=1%20UNION%20SELECT%20a", ip = "7.7.7.7", hit = true })
ok(r.blocked and r.status == 403, "特征命中返回 403")
ok(counter(r.dict, "sqli") == 1, "特征命中计入类目")

-- T9 特征封禁后：规则不再命中也拦截
NOW = NOW + 1
r = request({ uri = "/v1/log", ip = "7.7.7.7", hit = false })
ok(r.blocked, "特征封禁期内持续拦截")

-- T10 其它 IP 不受影响
r = request({ uri = "/v1/log", ip = "8.8.8.8" })
ok(not r.blocked, "封禁不影响其他 IP")

-- T11 统计页 JSON
ngx.var.uri = "/security/stats"
ngx.var.request_uri = "/security/stats"
ngx.status = nil
SAID = nil
EXITED = nil
local okjson, jerr = pcall(function() waf.stats() end)
if not okjson then print("JSON 错误: " .. tostring(jerr)) end
ok(okjson, "JSON 统计页输出成功")
ok(SAID and SAID:find('"blocked_total"', 1, true) ~= nil, "JSON 含 blocked_total")

-- T12 统计页 HTML
ngx.var.uri = "/security"
SAID = nil
pcall(function() waf.stats() end)
ok(SAID and SAID:find("LiteWAF 安全统计", 1, true) ~= nil, "HTML 统计页输出成功")

-- T13 S1 回归：/security 前缀变体必须走特征检查，不得绕过
r = request({ uri = "/securityXYZ", hit = true, ip = "11.1.1.1" })
ok(r.blocked, "/security 前缀变体不可绕过特征检查")
r = request({ uri = "/security/evil", hit = true, ip = "11.1.1.2" })
ok(r.blocked, "/security/ 子路径不可绕过特征检查")

-- T14 统计页两个精确 URI 仍跳过特征匹配
r = request({ uri = "/security", hit = true, ip = "12.1.1.1" })
ok(not r.blocked, "/security 精确匹配跳过特征")
r = request({ uri = "/security/stats", hit = true, ip = "12.1.1.2" })
ok(not r.blocked, "/security/stats 精确匹配跳过特征")

-- T15 封禁 TTL 到期自动解封
local banip = "13.1.1.1"
for _ = 1, 241 do
    r = request({ uri = "/v1/log", ip = banip })
end
ok(r.blocked, "T15 CC 触发封禁")
NOW = NOW + 601  -- 超过 cc.ban=600 秒
r = request({ uri = "/v1/log", ip = banip })
ok(not r.blocked, "封禁到期后自动解封")

print(fail == 0 and "全部通过" or (fail .. " 项失败"))
os.exit(fail == 0 and 0 or 1)
