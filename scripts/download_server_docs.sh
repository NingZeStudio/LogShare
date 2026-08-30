#!/bin/bash
# 拉取各服务端 / 代理端开发者文档到 RAG 知识库（排查用）。
#
# 收录（有 markdown 源的项目）：
#   PaperMC   https://github.com/PaperMC/docs        (paper/velocity/waterfall/folia/adventure)
#   Purpur    https://github.com/PurpurMC/PurpurDocs
#   Glowstone https://github.com/GlowstoneMC/glowstone.wiki
#   Geyser    https://github.com/GeyserMC/Geyser.wiki
#   Quilt     https://github.com/QuiltMC/docs        (quilt-config 等，Quilt 生态其余复用 Fabric 文档)
#
# 不收录（无 markdown 源，说明见 README）：
#   Bukkit/Spigot/BungeeCord —— 仅 HTML wiki/javadoc；
#   Arclight/Magma/Mohist —— 仓库无成体系开发者文档，其排查知识本质为 Forge 生态问题，
#                            已由 forge/ 与 日志分析/ 覆盖；
#   PocketMine —— GitHub wiki 仅 2 页。
#
# 用法：scripts/download_server_docs.sh
# 之后运行 `php bin/hyperf.php rag:build` 重建索引。
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
KNOWLEDGE_DIR="${ROOT}/rag/knowledge"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

fetch_git() { # $1 repo  $2 dest
    git clone -q --depth 1 "https://github.com/$1.git" "$2" || return 1
}

fetch_tarball() { # $1 repo  $2 branch  $3 dest
    curl -sL --max-time 180 "https://codeload.github.com/$1/tar.gz/refs/heads/$2" -o "$3.tgz" \
        && mkdir -p "$3" \
        && tar xzf "$3.tgz" -C "$3" --strip-components=1 \
        && rm -f "$3.tgz"
}

copy_md() { # $1 src dir  $2 dest dir  [$3 exclude regex for grep -E -v, default never matches]
    local src="$1" dst="$2"
    local exclude="${3:-'^$'}"
    mkdir -p "$dst"
    (cd "$src" && find . -type f \( -name '*.md' -o -name '*.mdx' \) \
        | grep -Ev "$exclude" \
        | while read -r f; do
            local target="${dst}/$(dirname "$f")"
            mkdir -p "$target"
            # 统一为 .md 后缀（MDX 组件文档一并纳入；已有 .md 不重复追加）
            local base
            base=$(basename "$f")
            base="${base%.mdx}"; base="${base%.md}"
            cp "$f" "${target}/${base}.md"
        done)
}

echo "==> PaperMC (paper/velocity/waterfall/folia/adventure)..."
rm -rf "${TMP_DIR}/papermc"
if fetch_tarball "PaperMC/docs" "main" "${TMP_DIR}/papermc"; then
    copy_md "${TMP_DIR}/papermc/src/content/docs" "${KNOWLEDGE_DIR}/papermc"
else
    echo "    WARN: tarball failed, trying git..."
    rm -rf "${TMP_DIR}/papermc"
    fetch_git "PaperMC/docs" "${TMP_DIR}/papermc" && copy_md "${TMP_DIR}/papermc/src/content/docs" "${KNOWLEDGE_DIR}/papermc"
fi

echo "==> Purpur..."
rm -rf "${TMP_DIR}/purpur"
fetch_tarball "PurpurMC/PurpurDocs" "master" "${TMP_DIR}/purpur" \
    || { rm -rf "${TMP_DIR}/purpur"; fetch_git "PurpurMC/PurpurDocs" "${TMP_DIR}/purpur"; }
[ -d "${TMP_DIR}/purpur" ] && copy_md "${TMP_DIR}/purpur" "${KNOWLEDGE_DIR}/purpur"

echo "==> Glowstone..."
rm -rf "${TMP_DIR}/glowstone"
fetch_git "GlowstoneMC/glowstone.wiki" "${TMP_DIR}/glowstone" || true
[ -d "${TMP_DIR}/glowstone" ] && copy_md "${TMP_DIR}/glowstone" "${KNOWLEDGE_DIR}/glowstone"

echo "==> Geyser..."
# 注意：GeyserMC/Geyser.wiki 是旧站重定向壳（21/24 文件仅为 "Moved to ..." 占位），
# 正式文档在 GeyserMC/geyserwiki 的 _docs/
rm -rf "${TMP_DIR}/geyser"
fetch_git "GeyserMC/geyserwiki" "${TMP_DIR}/geyser" || true
if [ -d "${TMP_DIR}/geyser/_docs" ]; then
    # find 输出带 ./ 前缀，排除模式须匹配路径片段而非行首锚定
    copy_md "${TMP_DIR}/geyser/_docs" "${KNOWLEDGE_DIR}/geyser" 'CONTRIBUTING|/README\.md$|/LICENSE$'
fi

echo "==> Quilt..."
rm -rf "${TMP_DIR}/quilt"
fetch_git "QuiltMC/docs" "${TMP_DIR}/quilt" || true
if [ -d "${TMP_DIR}/quilt/src/content/docs/en/developer" ]; then
    copy_md "${TMP_DIR}/quilt/src/content/docs/en/developer" "${KNOWLEDGE_DIR}/quilt"
fi

echo "==> Summary:"
for d in papermc purpur glowstone geyser quilt; do
    if [ -d "${KNOWLEDGE_DIR}/${d}" ]; then
        printf "  %-10s %s files\n" "$d" "$(find "${KNOWLEDGE_DIR}/${d}" -name '*.md' | wc -l)"
    fi
done

echo "==> Cleaning (frontmatter / MDX / JSX / admonition / HTML)..."
php "${ROOT}/scripts/clean_knowledge_docs.php"

echo "==> Next: php bin/hyperf.php rag:build"
