#!/bin/bash
set -euo pipefail

MAPPINGS_DIR="$(cd "$(dirname "$0")/.." && pwd)/mappings"
MAVEN_BASE="https://maven.fabricmc.net/net/fabricmc/yarn"
TMP_DIR="${MAPPINGS_DIR}/.tmp"
mkdir -p "$MAPPINGS_DIR" "$TMP_DIR"

VERSIONS=(
    # 1.14 (5)
    "1.14" "1.14.1" "1.14.2" "1.14.3" "1.14.4"
    # 1.15 (3)
    "1.15" "1.15.1" "1.15.2"
    # 1.16 (6)
    "1.16" "1.16.1" "1.16.2" "1.16.3" "1.16.4" "1.16.5"
    # 1.17 (2)
    "1.17" "1.17.1"
    # 1.18 (3)
    "1.18" "1.18.1" "1.18.2"
    # 1.19 (5)
    "1.19" "1.19.1" "1.19.2" "1.19.3" "1.19.4"
    # 1.20 (7)
    "1.20" "1.20.1" "1.20.2" "1.20.3" "1.20.4" "1.20.5" "1.20.6"
    # 1.21 (12)
    "1.21" "1.21.1" "1.21.2" "1.21.3" "1.21.4" "1.21.5"
    "1.21.6" "1.21.7" "1.21.8" "1.21.9" "1.21.10" "1.21.11"
)

find_latest_build() {
    local encoded="$1"
    local listing
    listing=$(curl -sf "https://maven.fabricmc.net/net/fabricmc/yarn/" 2>/dev/null) || { echo ""; return 1; }
    echo "$listing" | grep -o "${encoded}%2Bbuild\.[0-9]*" | sed 's/%2B/+/g' \
        | awk -F'build.' 'NR==1{m=$2;l=$0} {if($2>m){m=$2;l=$0}} END{print l}'
}

# Extract .tiny from a JAR, write gzipped output
# 参数经 argv 传入 Python（`python3 - args <<'EOF'`），避免 shell 变量拼进
# 代码字符串——版本号含单引号时会破坏语法乃至注入代码
jar_to_gz() {
    local jar_file="$1"
    local out_file="$2"
    python3 - "$jar_file" "$out_file" <<'PYEOF'
import sys, zipfile, gzip
jar_file, out_file = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(jar_file, 'r') as z:
    for name in z.namelist():
        if name.endswith('.tiny'):
            with gzip.open(out_file, 'wb') as g:
                g.write(z.read(name))
            break
PYEOF
}

download_one() {
    local version="$1"
    local build="$2"
    local gz_file="${MAPPINGS_DIR}/${version}.tiny.gz"
    local part_file="${gz_file}.part"

    if [[ -s "$gz_file" ]] && gzip -t "$gz_file" 2>/dev/null; then
        echo "  [skip] $version already exists"
        return 0
    fi

    local encoded=$(echo "$build" | sed 's/\+/%2B/g')
    local dir_url="${MAVEN_BASE}/${encoded}/"
    local listing
    listing=$(curl -sf "$dir_url" 2>/dev/null) || { echo "  [FAIL] $version: no directory"; return 1; }

    echo -n "  [down] $version ($build) ... "

    # 1. Direct tiny.gz
    if echo "$listing" | grep -q "yarn-${encoded}-tiny.gz"; then
        rm -f "$part_file"
        if curl -sfL --max-time 300 -o "$part_file" "${dir_url}yarn-${encoded}-tiny.gz" && gzip -t "$part_file" 2>/dev/null && [[ -s "$part_file" ]]; then
            mv -f "$part_file" "$gz_file"
            echo "OK ($(du -h "$gz_file" | cut -f1), tiny.gz)"
            return 0
        fi
        echo "FAILED (tiny.gz download)"
        return 1
    fi

    # 2. mergedv2.jar / v2.jar
    local jar_kind=""
    if echo "$listing" | grep -q "yarn-${encoded}-mergedv2.jar"; then
        jar_kind="mergedv2"
    elif echo "$listing" | grep -q "yarn-${encoded}-v2.jar"; then
        jar_kind="v2"
    fi

    if [[ -n "$jar_kind" ]]; then
        local jar_file="${TMP_DIR}/${version}.jar"
        if curl -sfL --max-time 300 -o "$jar_file" "${dir_url}yarn-${encoded}-${jar_kind}.jar"; then
            rm -f "$part_file"
            if jar_to_gz "$jar_file" "$part_file" && gzip -t "$part_file" 2>/dev/null && [[ -s "$part_file" ]]; then
                mv -f "$part_file" "$gz_file"
                rm -f "$jar_file"
                echo "OK ($(du -h "$gz_file" | cut -f1), $jar_kind)"
                return 0
            fi
            rm -f "$jar_file"
            echo "FAILED (extract)"
            return 1
        fi
        rm -f "$jar_file"
        echo "FAILED (jar download)"
        return 1
    fi

    echo "FAILED (no mapping artifact)"
    return 1
}

process_version() {
    local version="$1"
    local build

    # 已存在的文件先做完整性校验（gzip -t），损坏的映射不跳过、走重新下载；
    # 旧逻辑只看文件存在即跳过，损坏文件会被永久跳过且不重下
    if [[ -f "${MAPPINGS_DIR}/${version}.tiny.gz" ]]; then
        if [[ -s "${MAPPINGS_DIR}/${version}.tiny.gz" ]] && gzip -t "${MAPPINGS_DIR}/${version}.tiny.gz" 2>/dev/null; then
            echo "  [skip] $version already exists"
            return 2
        fi
        echo "  [corrupt] $version: existing file failed gzip validation, re-downloading"
        rm -f "${MAPPINGS_DIR}/${version}.tiny.gz"
    fi

    build=$(find_latest_build "$(echo "$version" | sed 's/\+/%2B/g')")
    if [[ -z "$build" ]]; then
        echo "  [FAIL] $version: no build found on Maven"
        return 1
    fi

    if download_one "$version" "$build"; then
        return 0
    fi
    return 1
}

# CLI: pass versions to fetch only those; default is the bundled list.
if [[ $# -gt 0 ]]; then
    TARGETS=("$@")
else
    TARGETS=("${VERSIONS[@]}")
fi

echo "=== SpinYarn Mapping Downloader ==="
echo "Target: ${#TARGETS[@]} versions"
echo "Output: $MAPPINGS_DIR"
echo ""

success=0
failed=0
skipped=0

for version in "${TARGETS[@]}"; do
    process_version "$version" && rc=0 || rc=$?
    if (( rc == 0 )); then
        (( success += 1 ))
    elif (( rc == 2 )); then
        (( skipped += 1 ))
    else
        (( failed += 1 ))
    fi
done

rm -rf "$TMP_DIR"
echo ""
echo "=== Done: $success ok, $failed failed, $skipped skipped ==="

if (( failed > 0 )); then
    exit 1
fi