#!/bin/bash

# LogShare-v1 API 批量测试脚本
# 用法：./test-api.sh [API_BASE_URL]
# 示例：./test-api.sh http://localhost:9300

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# API 基础地址
API_BASE="${1:-http://localhost:9300}"

# 测试结果统计
TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0

# 存储测试中生成的日志 ID 和 Token
declare -a LOG_IDS=()
declare -a LOG_TOKENS=()

# 从 JSON 中获取字段值（使用 jq）
get_json_field() {
    local json="$1"
    local field="$2"
    echo "$json" | jq -r --arg field "$field" '.[$field]' 2>/dev/null | head -1
}

# 打印分隔线
print_separator() {
    echo -e "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
}

# 打印测试标题
print_test_title() {
    echo -e "\n${BLUE}▶ $1${NC}"
}

# 打印请求信息
print_request() {
    echo -e "${YELLOW}请求:${NC}"
    echo "  $1"
    if [ -n "$2" ]; then
        echo "  $2"
    fi
}

# 打印响应信息
print_response() {
    echo -e "${CYAN}响应:${NC}"
    echo "$1" | jq . 2>/dev/null || echo "$1"
}

# 测试成功
test_passed() {
    PASSED_TESTS=$((PASSED_TESTS + 1))
    echo -e "${GREEN}✓ 测试通过${NC}"
}

# 测试失败
test_failed() {
    FAILED_TESTS=$((FAILED_TESTS + 1))
    echo -e "${RED}✗ 测试失败${NC}"
    if [ -n "$1" ]; then
        echo -e "${RED}  原因：$1${NC}"
    fi
}

# 执行测试
run_test() {
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
    local test_name="$1"
    local method="$2"
    local endpoint="$3"
    local data="$4"
    local headers="$5"
    local check_field="${6:-}"
    local expected_value="${7:-}"
    
    print_test_title "测试 $TOTAL_TESTS: $test_name"
    print_request "$method $API_BASE$endpoint" "$data"
    
    # 构建 curl 命令
    local curl_cmd="curl -s -w '\n%{http_code}' -X $method"
    
    if [ -n "$headers" ]; then
        curl_cmd="$curl_cmd $headers"
    fi
    
    if [ -n "$data" ]; then
        curl_cmd="$curl_cmd -H 'Content-Type: application/json' -d '$data'"
    fi
    
    curl_cmd="$curl_cmd '$API_BASE$endpoint'"
    
    # 执行请求，分离响应体和状态码
    local full_response
    full_response=$(eval "$curl_cmd" 2>/dev/null)
    
    # 最后一行是状态码
    local http_code
    http_code=$(echo "$full_response" | tail -n1)
    
    # 其余是响应体
    local response
    response=$(echo "$full_response" | sed '$d')
    
    print_response "$response"
    echo -e "${YELLOW}HTTP 状态码：${NC}$http_code"
    
    # 检查响应是否包含 PHP 错误
    if echo "$response" | grep -q "Fatal error\|<br />\|<b>Fatal error</b>"; then
        test_failed "服务器返回 PHP 错误"
        return
    fi
    
    # 验证结果
    local passed=0
    
    if [ "$check_field" = "http_code" ]; then
        if [ "$http_code" = "$expected_value" ]; then
            passed=1
        fi
    elif [ -n "$check_field" ]; then
        local check_result
        check_result=$(get_json_field "$response" "$check_field")
        if [ "$check_result" = "$expected_value" ]; then
            passed=1
        fi
    elif [ "$http_code" = "200" ] || [ "$http_code" = "201" ]; then
        # 没有指定检查字段，HTTP 2xx 即认为成功
        passed=1
    fi
    
    if [ $passed -eq 1 ]; then
        test_passed
    else
        if [ -n "$check_field" ]; then
            local actual
            if [ "$check_field" = "http_code" ]; then
                actual="$http_code"
            else
                actual=$(get_json_field "$response" "$check_field")
            fi
            test_failed "期望 $check_field=$expected_value，实际=$actual"
        else
            test_failed "HTTP 状态码：$http_code"
        fi
    fi
    
    # 保存日志 ID 和 Token（仅对 POST /1/log 成功响应）
    # 只保存有效的日志 ID（字母数字组合）
    local log_id
    log_id=$(get_json_field "$response" "id")
    if [ -n "$log_id" ] && [ "$log_id" != "null" ] && [ "$log_id" != "" ] && [[ "$log_id" =~ ^[a-zA-Z0-9]+$ ]]; then
        local log_token
        log_token=$(get_json_field "$response" "token")
        LOG_IDS+=("$log_id")
        LOG_TOKENS+=("$log_token")
        echo -e "${GREEN}已保存日志 ID: $log_id${NC}"
    fi
}

# 打印测试结果摘要
print_summary() {
    print_separator
    echo -e "\n${BLUE}测试结果摘要:${NC}"
    echo -e "  总测试数：${CYAN}$TOTAL_TESTS${NC}"
    echo -e "  通过：${GREEN}$PASSED_TESTS${NC}"
    echo -e "  失败：${RED}$FAILED_TESTS${NC}"
    
    if [ $FAILED_TESTS -eq 0 ]; then
        echo -e "\n${GREEN}🎉 所有测试通过！${NC}"
    else
        echo -e "\n${RED}⚠️  有 $FAILED_TESTS 个测试失败${NC}"
    fi
}

# 清理测试数据
cleanup() {
    if [ "$CLEANUP_DONE" = "1" ]; then
        return
    fi
    CLEANUP_DONE=1
    
    print_test_title "清理测试数据"
    
    if [ ${#LOG_IDS[@]} -eq 0 ]; then
        echo "没有需要清理的日志"
        return
    fi
    
    echo "正在删除 ${#LOG_IDS[@]} 个测试日志..."
    
    for i in "${!LOG_IDS[@]}"; do
        local log_id="${LOG_IDS[$i]}"
        local log_token="${LOG_TOKENS[$i]}"
        
        if [ "$log_token" != "null" ] && [ -n "$log_token" ]; then
            echo -n "  删除 $log_id ... "
            local response
            response=$(curl -s -X DELETE "$API_BASE/1/delete/$log_id" \
                -H "Authorization: Bearer $log_token")
            local result
            result=$(echo "$response" | jq -r '.success' 2>/dev/null)
            if [ "$result" = "true" ]; then
                echo -e "${GREEN}成功${NC}"
            else
                echo -e "${RED}失败${NC}"
            fi
        fi
    done
}

# 设置退出时的清理（只在脚本正常结束时执行）
trap 'if [ "$CLEANUP_DONE" != "1" ]; then cleanup; fi; CLEANUP_DONE=1' EXIT

# 主测试流程
main() {
    CLEANUP_DONE=0
    print_separator
    echo -e "${BLUE}LogShare-v1 API 批量测试脚本${NC}"
    print_separator
    echo -e "${YELLOW}API 地址：${NC}$API_BASE"
    echo ""
    
    # ========== 基础功能测试 ==========
    print_separator
    echo -e "${CYAN}【基础功能测试】${NC}"
    print_separator
    
    # 测试 1: 获取 API 首页
    run_test "获取 API 首页信息" "GET" "/" "" "" "http_code" "200"
    
    # 测试 2: 获取限制信息
    run_test "获取限制信息" "GET" "/1/limits" "" "" "http_code" "200"
    
    # 测试 3: 获取过滤器信息
    run_test "获取过滤器信息" "GET" "/1/filters" "" "" "success" "true"
    
    # ========== 日志提交测试 ==========
    print_separator
    echo -e "${CYAN}【日志提交测试】${NC}"
    print_separator
    
    # 测试 4: 提交简单日志
    run_test "提交简单日志" "POST" "/1/log" \
        '{"content":"[Server thread/INFO]: Hello World"}' \
        "" "success" "true"
    
    # 测试 5: 提交带元数据的日志
    run_test "提交带元数据的日志" "POST" "/1/log" \
        '{"content":"[Server thread/INFO]: Server started","metadata":[{"key":"version","value":"1.20.1","label":"Minecraft Version","visible":true}],"source":"test-script"}' \
        "" "success" "true"
    
    # ========== 隐私过滤测试 ==========
    print_separator
    echo -e "${CYAN}【隐私过滤测试】${NC}"
    print_separator
    
    # 测试 6: IPv4 地址过滤
    run_test "IPv4 地址过滤" "POST" "/1/log" \
        '{"content":"[Server thread/INFO]: Player connected from 192.168.1.100\n[Server thread/INFO]: Another player from 10.0.0.50"}' \
        "" "success" "true"
    
    # 测试 7: IPv6 地址过滤
    run_test "IPv6 地址过滤" "POST" "/1/log" \
        '{"content":"[Server thread/INFO]: Player connected from 2001:0db8:85a3:0000:0000:8a2e:0370:7334"}' \
        "" "success" "true"
    
    # 测试 8: 用户名过滤
    run_test "用户名过滤" "POST" "/1/log" \
        '{"content":"[Server thread/INFO]: Player Steve joined the game\n[Server thread/INFO]: /home/username/.minecraft"}' \
        "" "success" "true"
    
    # 测试 9: Access Token 过滤
    run_test "Access Token 过滤" "POST" "/1/log" \
        '{"content":"[Server thread/INFO]: Token: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0"}' \
        "" "success" "true"
    
    # 测试 10: 混合敏感信息
    run_test "混合敏感信息过滤" "POST" "/1/log" \
        '{"content":"[Server thread/INFO]: User admin from 192.168.0.1 logged in with token abc123xyz\n[Server thread/WARN]: Failed connection from 2001:db8::1"}' \
        "" "success" "true"
    
    # ========== 日志分析测试 ==========
    print_separator
    echo -e "${CYAN}【日志分析测试】${NC}"
    print_separator
    
    # 测试 11: 分析日志
    run_test "分析日志" "POST" "/1/analyse" \
        '{"content":"[Server thread/INFO]: Starting minecraft server version 1.20.1"}' \
        "" "http_code" "200"
    
    # ========== 日志读取测试 ==========
    print_separator
    echo -e "${CYAN}【日志读取测试】${NC}"
    print_separator
    
    # 测试 12: 获取原始日志（使用第一个日志 ID）
    if [ ${#LOG_IDS[@]} -gt 0 ]; then
        run_test "获取原始日志" "GET" "/1/raw/${LOG_IDS[0]}" "" "" "http_code" "200"
    else
        echo -e "${YELLOW}跳过：没有可用的日志 ID${NC}"
    fi
    
    # 测试 13: 获取日志洞察
    if [ ${#LOG_IDS[@]} -gt 0 ]; then
        run_test "获取日志洞察" "GET" "/1/insights/${LOG_IDS[0]}" "" "" ""
    else
        echo -e "${YELLOW}跳过：没有可用的日志 ID${NC}"
    fi
    
    # ========== 错误处理测试 ==========
    print_separator
    echo -e "${CYAN}【错误处理测试】${NC}"
    print_separator
    
    # 测试 14: 测试不存在的日志
    run_test "测试不存在的日志" "GET" "/1/raw/nonexistent" "" "" "http_code" "404"
    
    # 测试 15: 测试缺少 content 字段
    run_test "测试缺少 content 字段" "POST" "/1/log" \
        '{"metadata":[]}' \
        "" "http_code" "400"
    
    # 测试 16: 测试错误的 Token
    if [ ${#LOG_IDS[@]} -gt 0 ]; then
        run_test "测试错误的 Token" "DELETE" "/1/delete/${LOG_IDS[0]}" \
            "" "-H 'Authorization: Bearer invalid_token'" "success" "false"
    else
        echo -e "${YELLOW}跳过：没有可用的日志 ID${NC}"
    fi
    
    # ========== 特殊字符测试 ==========
    print_separator
    echo -e "${CYAN}【特殊字符测试】${NC}"
    print_separator
    
    # 测试 17: 中文内容
    run_test "中文内容" "POST" "/1/log" \
        '{"content":"[Server thread/INFO]: 服务器已启动\n[Server thread/INFO]: 玩家 测试 加入了游戏"}' \
        "" "success" "true"
    
    # 测试 18: 特殊字符
    run_test "特殊字符" "POST" "/1/log" \
        '{"content":"[Server thread/INFO]: Test with special chars: <>&\\n\\t\\r"}' \
        "" "success" "true"
    
    # 测试 19: 长日志内容
    local long_content=""
    for i in {1..100}; do
        long_content+="[Server thread/INFO]: This is log line number $i\n"
    done
    run_test "长日志内容 (100 行)" "POST" "/1/log" \
        "{\"content\":\"$long_content\"}" \
        "" "success" "true"
    
    # 打印摘要
    print_separator
    print_summary
}

# 执行主流程
main
