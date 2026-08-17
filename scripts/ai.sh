#!/usr/bin/env bash
#
# ai.sh — 调用 LogShare AI 分析（SSE），解析 data 块并做 codex 风格展示
#
# 用法：
#   ai.sh <log-id>                 分析已存储日志
#   ai.sh -c "日志内容"             直接分析内容（不落盘）
#   ai.sh -t <log-id>              显示思维链（thinking）
#   ai.sh -u http://host:9300 ...  指定服务地址（默认 $LOGSHARE_URL 或 http://127.0.0.1:9300）
#
# 依赖：curl、jq

set -euo pipefail

BASE_URL="${LOGSHARE_URL:-http://127.0.0.1:9300}"
SHOW_THINKING=0
CONTENT=""
ID=""

usage() {
    sed -n '2,10p' "$0" | sed 's/^# \{0,1\}//'
    exit 1
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        -t|--thinking) SHOW_THINKING=1; shift ;;
        -u|--url)      BASE_URL="$2"; shift 2 ;;
        -c|--content)  CONTENT="$2"; shift 2 ;;
        -h|--help)     usage ;;
        -*)            echo "未知参数: $1" >&2; usage ;;
        *)             ID="$1"; shift ;;
    esac
done

# 颜色（终端支持时）
if [[ -t 1 ]]; then
    C_DIM=$'\033[2m'   C_RESET=$'\033[0m'
    C_TOOL=$'\033[36m' C_BODY=$'\033[0m'
    C_THINK=$'\033[90m'
else
    C_DIM="" C_RESET="" C_TOOL="" C_BODY="" C_THINK=""
fi

header() {
    printf '%s\n' "════════════════════════════════════════════"
    printf '%s\n' " LogShare AI 分析  ${ID:+（ID: $ID）}"
    printf '%s\n' "════════════════════════════════════════════"
}

# 构造请求 URL / 参数
if [[ -n "$CONTENT" ]]; then
    URL="$BASE_URL/v1/ai/analyse"
    REQ_ARGS=(--data-raw "$(jq -nc --arg c "$CONTENT" '{content: $c}')" \
              -H 'Content-Type: application/json')
else
    [[ -z "$ID" ]] && usage
    URL="$BASE_URL/v1/ai/$ID"
    REQ_ARGS=()
fi

header

# 正文开始标记（避免在纯工具阶段提前打印分隔线）
BODY_STARTED=0
thinking_buf=""

curl -sN "${REQ_ARGS[@]}" "$URL" \
| while IFS= read -r line; do

    # ── 事件标记行 ──
    case "$line" in
        "event: status") continue ;;
        "event: done")   printf '\n%s\n' "${C_DIM}── 结束 ──${C_RESET}"; continue ;;
        "event: error")  ;;
    esac

    # ── data 行 ──
    [[ "$line" == "data: "* ]] || continue
    JSON="${line#data: }"

    # 错误事件
    if [[ "$line" == "data: " && $(echo "$JSON" | jq -r '.error // empty' 2>/dev/null) != "" ]]; then
        printf '\n%s\n' "❌ $(echo "$JSON" | jq -r '.error')"
        continue
    fi

    # status 事件（thinking / tool / tool_result / limit）
    TYPE=$(echo "$JSON" | jq -r '.type // empty' 2>/dev/null || true)
    case "$TYPE" in
        thinking)
            [[ "$SHOW_THINKING" == "1" ]] || continue
            DELTA=$(echo "$JSON" | jq -r '.delta // empty')
            thinking_buf+="$DELTA"
            # 累积到换行或长度阈值再输出，避免逐 token 刷屏
            if [[ "$thinking_buf" == *$'\n'* || ${#thinking_buf} -gt 60 ]]; then
                printf '%s\n' "${C_THINK}[思考] ${thinking_buf}${C_RESET}"
                thinking_buf=""
            fi
            ;;
        tool)
            NAME=$(echo "$JSON" | jq -r '.name // empty')
            ARGS=$(echo "$JSON" | jq -r '.arguments // empty')
            printf '\n%s\n' "${C_TOOL}▸ 工具 ${NAME}${C_RESET}"
            if [[ "$ARGS" != "" && "$ARGS" != "{}" && "$ARGS" != "null" ]]; then
                printf '%s\n' "${C_DIM}    参数: ${ARGS}${C_RESET}"
            fi
            ;;
        tool_result)
            NAME=$(echo "$JSON" | jq -r '.name // empty')
            SUMMARY=$(echo "$JSON" | jq -r '.summary // empty')
            printf '%s\n' "${C_DIM}  └ 结果:${C_RESET}"
            echo "$SUMMARY" | sed 's/^/     /'
            ;;
        limit)
            ROUNDS=$(echo "$JSON" | jq -r '.rounds // empty')
            printf '%s\n' "${C_DIM}[已达最大轮数 ${ROUNDS}]${C_RESET}"
            ;;
    esac

    # 正文 content 增量
    CONTENT_DELTA=$(echo "$JSON" | jq -r '.choices[0].delta.content // empty' 2>/dev/null || true)
    if [[ -n "$CONTENT_DELTA" ]]; then
        if [[ "$BODY_STARTED" == "0" ]]; then
            printf '%s\n' "───────────────── 分析结果 ─────────────────"
            BODY_STARTED=1
        fi
        printf '%s' "$CONTENT_DELTA"
    fi

done

# 刷新残留的思维链缓冲
[[ -n "$thinking_buf" && "$SHOW_THINKING" == "1" ]] && printf '%s\n' "${C_THINK}[思考] ${thinking_buf}${C_RESET}"

printf '\n%s\n' "════════════════════════════════════════════"
