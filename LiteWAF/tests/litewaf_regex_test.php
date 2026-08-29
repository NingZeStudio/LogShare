# LiteWAF 规则正误样本回归测试（PHP PCRE 与 OpenResty ngx.re 同源）
# 用法：php LiteWAF/tests/litewaf_regex_test.php

<?php
$fail = 0;
$luaFile = __DIR__ . '/../lua/litewaf.lua';
$lua = file_get_contents($luaFile);
if (!preg_match('/-- RULES-BEGIN(.*?)-- RULES-END/s', $lua, $m)) {
    exit("未找到 RULES-BEGIN/END 标记\n");
}
if (!preg_match_all('/\{\s*"([a-z]+)",\s*\[\[(.*?)\]\]\s*\}/s', $m[1], $raw, PREG_SET_ORDER)) {
    exit("未解析到任何规则\n");
}
$rules = [];
foreach ($raw as $r) {
    $rules[] = ['cat' => $r[1], 're' => $r[2]];
}
echo '已加载规则数：' . count($rules) . "\n";

// 与 litewaf.lua 一致的匹配语义：按规则表顺序，命中即停；
// URI 请求先查原始 request_uri，未命中再查解码后 uri（与生产双 subject 一致）
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
