<?php

try {
    RequestValidator::validateMethod('GET');
    $logId = RequestValidator::extractId('/1/ai/');
} catch (ApiError $e) {
    $e->output();
}

$id = new Id($logId);
$log = new Log($id);

if (!$log->exists()) {
    $error = new ApiError(404, "Log not found.");
    $error->output();
}

$log->renew();

// 检查 AI 分析结果是否已缓存（5 分钟 TTL）
$aiCacheKey = "ai:analysis:" . $id->getRaw();
$cacheTTL = 300;

try {
    $cachedAnalysis = \Cache\RedisCache::Get($aiCacheKey);
    if ($cachedAnalysis !== null) {
        $analysis = json_decode($cachedAnalysis, true);
        if (is_array($analysis)) {
            // 缓存命中，直接返回 JSON（不走流式）
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
    \Client\AiClient::analyzeStream($log->getContent(), $aiCacheKey, $cacheTTL);
} catch (\Exception $e) {
    $error = new ApiError(500, "AI analysis failed: " . $e->getMessage());
    $error->output();
}
