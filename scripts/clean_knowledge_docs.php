<?php
declare(strict_types=1);

/**
 * RAG 知识库文档清洗器。
 *
 * 处理上游 markdown 源中污染检索质量的内容：
 *  - 重定向占位文件（"Moved to ..."）与元文件（README/LICENSE/_Sidebar 等）
 *  - YAML frontmatter（title 提取为 H1，其余剥离）
 *  - MDX import/export 语句与 JSX 组件标签
 *  - admonition 围栏（:::note[Title] ... :::）
 *  - 结构性 HTML 标签（div/alert/tab/img），保留内部文本
 *  - shields.io 徽章行、3+ 连续空行
 *
 * fenced code block 内部不做任何处理（MiniMessage 等标签语法是教学内容）。
 * 幂等：已清洗的文件再次执行无变化。
 *
 * 用法：php scripts/clean_knowledge_docs.php [dir ...]
 *       无参数时清洗全部机器拉取的知识库目录。
 */

$root = dirname(__DIR__);
$targets = ($argv[1] ?? null) !== null ? array_slice($argv, 1) : [
    'papermc', 'purpur', 'glowstone', 'geyser', 'quilt', 'forge', 'neoforge',
];

const META_FILES = ['readme.md', 'license.md', 'contributing.md', '_sidebar.md', '_footer.md'];

/**
 * 仅机器拉取的上游目录适用「元文件删除」规则；
 * 手写蒸馏目录（*-issues/patterns/renderers 等）的 README 是有价值的专题总览，不可删。
 */
const UPSTREAM_DIRS = ['papermc', 'purpur', 'glowstone', 'geyser', 'quilt', 'forge', 'neoforge'];

$totalFiles = 0;
$removed = 0;
$cleaned = 0;

foreach ($targets as $dir) {
    $path = $root . '/rag/knowledge/' . $dir;
    if (!is_dir($path)) {
        continue;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );
    /** @var SplFileInfo $file */
    foreach ($it as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') {
            continue;
        }
        $totalFiles++;
        $base = strtolower($file->getBasename());

        // 1. 元文件直接删除（README/_Sidebar 等对检索零价值）
        if (in_array($dir, UPSTREAM_DIRS, true) && in_array($base, META_FILES, true)) {
            @unlink($file->getPathname());
            $removed++;
            continue;
        }

        $content = file_get_contents($file->getPathname());
        if ($content === false || trim($content) === '') {
            @unlink($file->getPathname());
            $removed++;
            continue;
        }

        // 2. "Moved to ..." 重定向占位壳
        if (preg_match('/^\s*moved to\s+\S+/i', $content)) {
            @unlink($file->getPathname());
            $removed++;
            continue;
        }

        $cleanedContent = cleanDocument(str_replace(["\r\n", "\r"], "\n", (string) $content));

        // 3. 清洗后有效内容过薄的纯导航/骨架页删除
        if (mb_strlen(trim((string) preg_replace('/\s+/', ' ', strip_tags($cleanedContent)))) < 200) {
            @unlink($file->getPathname());
            $removed++;
            continue;
        }

        if ($cleanedContent !== $content) {
            file_put_contents($file->getPathname(), $cleanedContent);
            $cleaned++;
        }
    }
}

echo "Cleaned: {$totalFiles} files scanned, {$cleaned} cleaned, {$removed} removed.\n";
exit(0);

/**
 * Clean a single markdown document (single-pass line state machine).
 */
function cleanDocument(string $content): string
{
    // ── YAML frontmatter: extract title, drop the rest ──
    $frontTitle = null;
    if (preg_match('/^---\n(.*?)\n---\n/s', $content, $m)) {
        if (preg_match('/^title:\s*["\']?(.+?)["\']?\s*$/m', $m[1], $t)) {
            $frontTitle = trim($t[1]);
        }
        $content = substr($content, strlen($m[0]));
    }

    $lines = explode("\n", $content);
    $out = [];
    $inFence = false;      // inside a ``` fenced block — pass through untouched
    $pendingImport = false; // multi-line MDX import continuation

    foreach ($lines as $line) {
        if (preg_match('/^```/', trim($line))) {
            $inFence = !$inFence;
            $out[] = $line;
            $pendingImport = false;
            continue;
        }
        if ($inFence) {
            $out[] = $line;
            continue;
        }

        $trim = trim($line);

        // ── MDX import/export (may span multiple lines with unbalanced braces) ──
        if ($pendingImport) {
            $pendingImport = substr_count($trim, '{') > substr_count($trim, '}');
            continue;
        }
        if (preg_match('/^(import|export)\s/', $trim)) {
            $pendingImport = substr_count($trim, '{') > substr_count($trim, '}');
            continue;
        }

        // ── JSX component tags ──
        if (preg_match('/^<\/?[A-Z][A-Za-z]*/', $trim)) {
            if (preg_match('/^<TabItem\b[^>]*label="([^"]+)"/', $trim, $mm)) {
                $out[] = '**' . $mm[1] . '**';
                $out[] = '';
            } elseif (!preg_match('/^<\//', $trim)) {
                // opening tag: keep any meaningful inline text after the tag itself
                $inline = trim((string) preg_replace('/^<[A-Z][^>]*>/', '', $trim));
                if ($inline !== '') {
                    $out[] = $inline;
                }
            }
            // closing tags and self-closing components are dropped silently
            continue;
        }

        // ── admonition fences ──
        if (preg_match('/^:::([a-z]+)(?:\[([^\]]*)\])?\s*$/i', $trim, $am)) {
            $kind = ucfirst(strtolower($am[1]));
            $title = $am[2] ?? '';
            $out[] = '> **' . ($title !== '' ? "{$kind}: {$title}" : $kind) . '**';
            continue;
        }
        if ($trim === ':::') {
            continue;
        }

        // ── shield badge lines ──
        if (str_contains($line, 'img.shields.io')) {
            continue;
        }

        // ── structural HTML on this line ──
        if (preg_match('/<(div|span|a|img|li|ul|p|table|tbody|thead|tr|td|th|h[1-6])[\s>\/]/i', $trim)
            || str_contains($line, '<br')) {
            $stripped = stripStructuralHtml($line);
            if (trim($stripped) !== '') {
                $out[] = $stripped;
            }
            continue;
        }

        // ── jekyll liquid leftovers {{ ... }} ──
        if (str_contains($trim, '{{') && str_contains($trim, '}}')) {
            $stripped = trim((string) preg_replace('/\{\{[^}]*\}\}/', '', $line));
            if ($stripped !== '') {
                $out[] = $stripped;
            }
            continue;
        }

        $out[] = $line;
    }

    $result = implode("\n", $out);

    // inline structural tags spanning within kept paragraphs → newline separators
    $result = (string) preg_replace('/<\/?(div|span|ul|li|table|tbody|thead|tr|td|th)\b[^>]*>/i', "\n", $result);
    $result = (string) preg_replace('/<br\s*\/?>?/i', "\n", $result);

    // ── frontmatter title → H1 when the document lacks one ──
    if ($frontTitle !== null && !preg_match('/^#\s/m', ltrim($result))) {
        $result = "# {$frontTitle}\n\n" . ltrim($result);
    }

    // collapse blank runs
    $result = (string) preg_replace("/\n{3,}/", "\n\n", $result);

    return $result;
}

/**
 * Strip structural html tags from a single line, preserving inner text.
 */
function stripStructuralHtml(string $line): string
{
    $line = (string) preg_replace('/<img[^>]*alt="([^"]*)"[^>]*>/i', '[image: $1]', $line);
    $line = (string) preg_replace('/<img[^>]*>/i', '', $line);
    $line = (string) preg_replace('/<a\s[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', '$2', $line);
    return rtrim(strip_tags($line));
}
