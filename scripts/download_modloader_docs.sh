#!/bin/bash
# 拉取 Forge / NeoForge 最新开发者文档（markdown 源）到 RAG 知识库。
#
# 来源：
#   Forge    https://github.com/MinecraftForge/Documentation  (docs/)
#   NeoForge https://github.com/neoforged/Documentation       (docs/，仅当前版本，
#                                                             排除 versioned_docs 历史版本)
#
# 用法：scripts/download_modloader_docs.sh
# 之后运行 `php bin/hyperf.php rag:build` 重建索引。
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
KNOWLEDGE_DIR="${ROOT}/rag/knowledge"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

FORGE_REPO="https://github.com/MinecraftForge/Documentation"
NEOFORGE_REPO="https://github.com/neoforged/Documentation"

echo "==> Cloning Forge documentation..."
git clone --depth 1 "${FORGE_REPO}" "${TMP_DIR}/forge"

echo "==> Cloning NeoForge documentation..."
git clone --depth 1 "${NEOFORGE_REPO}" "${TMP_DIR}/neoforge"

# 目标目录整体替换，保证知识库与上游一致（删除的文档不会残留）
rm -rf "${KNOWLEDGE_DIR}/forge" "${KNOWLEDGE_DIR}/neoforge"
mkdir -p "${KNOWLEDGE_DIR}/forge" "${KNOWLEDGE_DIR}/neoforge"

echo "==> Copying markdown sources..."
# 仅取 docs/ 当前版本文档；排除贡献指南等非开发内容
(cd "${TMP_DIR}/forge/docs" && find . -type f -name '*.md' ! -iname 'CONTRIBUTING.md' \
    | while read -r f; do
        mkdir -p "${KNOWLEDGE_DIR}/forge/$(dirname "$f")"
        cp "$f" "${KNOWLEDGE_DIR}/forge/$f"
    done)

(cd "${TMP_DIR}/neoforge/docs" && find . -type f -name '*.md' ! -iname 'CONTRIBUTING.md' \
    | while read -r f; do
        mkdir -p "${KNOWLEDGE_DIR}/neoforge/$(dirname "$f")"
        cp "$f" "${KNOWLEDGE_DIR}/neoforge/$f"
    done)

FORGE_COUNT=$(find "${KNOWLEDGE_DIR}/forge" -name '*.md' | wc -l)
NEOFORGE_COUNT=$(find "${KNOWLEDGE_DIR}/neoforge" -name '*.md' | wc -l)

echo "==> Done. forge: ${FORGE_COUNT} files, neoforge: ${NEOFORGE_COUNT} files"
echo "==> Next: php bin/hyperf.php rag:build"
