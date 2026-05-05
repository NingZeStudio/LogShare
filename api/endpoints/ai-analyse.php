<?php

try {
    RequestValidator::validateMethod('POST');
} catch (ApiError $e) {
    $e->output();
}

$contentResult = (new ContentParser())->getContent();

if ($contentResult instanceof ApiError) {
    $contentResult->output();
}

$content = is_array($contentResult) ? $contentResult['content'] : $contentResult;

if (empty($content)) {
    $error = new ApiError(400, "Content is required.");
    $error->output();
}

// 基于内容哈希缓存（5 分钟 TTL）
$contentHash = hash('sha256', $content);
$aiCacheKey = "ai:analysis:hash:" . $contentHash;
$cacheTTL = 300;

try {
    $cachedAnalysis = \Cache\RedisCache::Get($aiCacheKey);
    if ($cachedAnalysis !== null) {
        $analysis = json_decode($cachedAnalysis, true);
        if (is_array($analysis)) {
            ApiResponse::success([
                'analysis' => $analysis,
                'cached' => true
            ], 'AI analysis completed (from cache).');
        }
    }
} catch (\Exception $e) {
    error_log("[AI Cache] 读取失败: " . $e->getMessage());
}

// 缓存未命中，流式输出
try {
    \Client\AiClient::analyzeStream($content, $aiCacheKey, $cacheTTL);
} catch (\Exception $e) {
    $error = new ApiError(500, "AI analysis failed: " . $e->getMessage());
    $error->output();
}
