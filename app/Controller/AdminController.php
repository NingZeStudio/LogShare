<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\AnalysisMode;
use App\Agent\AnalysisRecordManager;
use App\Agent\PromptManager;
use App\Agent\ToolManager;
use App\ApiError;
use App\Config;
use App\Id;
use App\Log;
use App\Middleware\AdminAuthMiddleware;
use App\Rag\RagManager;
use App\Storage\StorageInterface;
use App\System\AnalyticsService;
use App\System\AuditLogManager;
use App\System\SecurityService;
use App\System\SpinYarnManager;
use App\System\StorageHealthService;
use App\System\SystemLogManager;
use App\Telemetry\TelemetryService;
use App\Version;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;
use Psr\Http\Message\ResponseInterface;

/**
 * Controller for Admin operations under /{version:v?1}/admin.
 *
 * Protected by AdminAuthMiddleware (requires valid admin Bearer token).
 */
#[Controller(prefix: '/{version:v?1}/admin')]
#[Middleware(AdminAuthMiddleware::class)]
class AdminController extends AbstractController
{
    /**
     * @return class-string<StorageInterface>
     */
    private function getStorageClass(): string
    {
        $storageConfig = Config::Get('storage');
        $storageId = $storageConfig['storageId'] ?? 's';
        $storages = $storageConfig['storages'] ?? [];
        if (!isset($storages[$storageId]['class'])) {
            throw new ApiError(500, "Primary storage [{$storageId}] is not configured");
        }
        /** @var class-string<StorageInterface> $storageClass */
        $storageClass = $storages[$storageId]['class'];
        return $storageClass;
    }

    #[GetMapping(path: 'logs')]
    public function listLogs(): ResponseInterface
    {
        $params = $this->request->getQueryParams();

        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $source = isset($params['source']) && $params['source'] !== '' ? (string) $params['source'] : null;
        $since = isset($params['since']) && ctype_digit((string) $params['since']) ? (int) $params['since'] : null;
        $until = isset($params['until']) && ctype_digit((string) $params['until']) ? (int) $params['until'] : null;
        $keyword = isset($params['keyword']) && trim((string) $params['keyword']) !== '' ? trim((string) $params['keyword']) : null;

        $storage = $this->getStorageClass();

        $items = $storage::List($limit, $offset, $source, $since, $until, $keyword);
        $total = $storage::Count($source, $since, $until, $keyword);

        return $this->respondSuccess([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => (int) ceil($total / $limit),
        ], 'Logs retrieved successfully');
    }

    #[GetMapping(path: 'logs/{id}')]
    public function getLog(string $id): ResponseInterface
    {
        $logId = trim($id);
        if ($logId === '') {
            throw new ApiError(400, 'Log ID is required');
        }

        try {
            $parsedId = new Id($logId);
        } catch (\Throwable) {
            throw new ApiError(400, "Invalid log ID format: {$logId}");
        }

        $log = new Log($parsedId);
        if (!$log->exists()) {
            throw new ApiError(404, "Log not found: {$logId}");
        }

        return $this->respondSuccess([
            'id' => $log->getId()?->get() ?? $logId,
            'size' => $log->getSize(),
            'lines' => $log->getLineNumbers(),
            'created' => $log->getCreated(),
            'expires' => $log->getExpires(),
            'source' => $log->getSource(),
            'files' => $log->getFiles(),
            'metadata' => $log->getMetadata(),
            'content' => $log->getContent(),
        ], 'Log details retrieved successfully');
    }

    #[DeleteMapping(path: 'logs/{id}')]
    public function deleteLogs(string $id): ResponseInterface
    {
        $logIds = array_values(array_filter(array_map('trim', explode(',', $id)), fn($i) => $i !== ''));
        if (empty($logIds)) {
            throw new ApiError(400, 'At least one valid ID is required');
        }

        $results = [
            'deleted' => [],
            'failed' => [],
        ];

        foreach ($logIds as $logId) {
            try {
                $parsedId = new Id($logId);
                $log = new Log($parsedId);
            } catch (\Throwable $e) {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Invalid log ID: {$e->getMessage()}",
                    'code' => 400,
                ];
                continue;
            }

            if (!$log->exists()) {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Log not found: {$logId}",
                    'code' => 404,
                ];
                continue;
            }

            if ($log->delete()) {
                $results['deleted'][] = $logId;
            } else {
                $results['failed'][] = [
                    'id' => $logId,
                    'message' => "Failed to delete log: {$logId}",
                    'code' => 500,
                ];
            }
        }

        if (empty($results['deleted'])) {
            $errorMessages = array_column($results['failed'], 'message');
            return $this->respondError('Failed to delete logs: ' . implode('; ', $errorMessages), 400, $results['failed']);
        }

        AuditLogManager::record('log.delete', implode(',', $results['deleted']), [
            'total' => count($logIds),
            'deletedCount' => count($results['deleted']),
            'failedCount' => count($results['failed']),
        ], true, 'admin', $this->getClientIp());

        return $this->respondSuccess([
            'deleted' => $results['deleted'],
            'failed' => $results['failed'],
            'total' => count($logIds),
            'deletedCount' => count($results['deleted']),
            'failedCount' => count($results['failed']),
        ], 'Log deletion completed');
    }

    #[GetMapping(path: 'system/queue')]
    public function getQueueStatus(): ResponseInterface
    {
        $queueCfg = \App\Ai\AnalysisQueue::config();
        $depth = 0;
        try {
            $depth = \App\Ai\AnalysisQueue::queueDepth();
        } catch (\Throwable) {
            // Redis may be down or unconfigured
        }

        return $this->respondSuccess([
            'enabled' => $queueCfg['enabled'],
            'depth' => $depth,
            'isPaused' => \App\System\AiMetricsService::isPaused(),
            'maxQueue' => (int) $queueCfg['maxQueue'],
            'maxConcurrent' => (int) $queueCfg['maxConcurrent'],
            'waitTimeout' => (int) $queueCfg['waitTimeout'],
            'claimIdleMs' => (int) $queueCfg['claimIdleMs'],
            'jobTtl' => (int) $queueCfg['jobTtl'],
            'failOpen' => (bool) $queueCfg['failOpen'],
        ], 'Queue status retrieved successfully');
    }

    #[GetMapping(path: 'ai/metrics')]
    public function getAiMetrics(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $days = isset($params['days']) ? (int) $params['days'] : 7;
        $data = \App\System\AiMetricsService::getMetrics($days);
        $data['scoreboard'] = AnalysisRecordManager::getScoreboard($days);
        return $this->respondSuccess($data, 'AI metrics retrieved successfully');
    }

    #[GetMapping(path: 'ai/queue/inspect')]
    public function inspectAiQueue(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $limit = isset($params['limit']) ? (int) $params['limit'] : 20;
        $data = \App\System\AiMetricsService::inspectQueue($limit);
        return $this->respondSuccess($data, 'AI queue inspected successfully');
    }

    #[PostMapping(path: 'ai/queue/pause')]
    public function pauseAiQueue(): ResponseInterface
    {
        \App\System\AiMetricsService::setPaused(true);
        AuditLogManager::record('ai.queue.pause', 'queue', [], true, 'admin', $this->getClientIp());
        return $this->respondSuccess(['paused' => true], 'AI queue consumer paused');
    }

    #[PostMapping(path: 'ai/queue/resume')]
    public function resumeAiQueue(): ResponseInterface
    {
        \App\System\AiMetricsService::setPaused(false);
        AuditLogManager::record('ai.queue.resume', 'queue', [], true, 'admin', $this->getClientIp());
        return $this->respondSuccess(['paused' => false], 'AI queue consumer resumed');
    }

    #[PostMapping(path: 'ai/queue/flush')]
    public function flushAiQueue(): ResponseInterface
    {
        $res = \App\System\AiMetricsService::flushQueue();
        AuditLogManager::record('ai.queue.flush', 'queue', $res, true, 'admin', $this->getClientIp());
        return $this->respondSuccess($res, 'AI queue backlog flushed');
    }

    #[PostMapping(path: 'ai/queue/dead/clear')]
    public function clearAiDeadJobs(): ResponseInterface
    {
        $cleared = \App\System\AiMetricsService::clearDeadJobs();
        AuditLogManager::record('ai.dead.clear', 'dead_jobs', ['cleared' => $cleared], true, 'admin', $this->getClientIp());
        return $this->respondSuccess(['cleared' => $cleared], 'AI dead jobs cleared');
    }

    // ── LLM 已知领域知识管理（Known Domain Knowledge） ──

    #[GetMapping(path: 'ai/domain-knowledge')]
    public function listDomainKnowledge(): ResponseInterface
    {
        $items = \App\System\DomainKnowledgeManager::getAll();
        return $this->respondSuccess([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    #[PostMapping(path: 'ai/domain-knowledge')]
    public function createDomainKnowledge(): ResponseInterface
    {
        $body = $this->getParsedBody();
        $content = isset($body['content']) ? trim((string) $body['content']) : '';
        $enabled = isset($body['enabled']) ? (bool) $body['enabled'] : true;

        if ($content === '') {
            throw new ApiError(400, '领域知识内容不能为空');
        }

        $charCount = mb_strlen($content, 'UTF-8');
        if ($charCount > \App\System\DomainKnowledgeManager::MAX_ITEM_LENGTH) {
            throw new ApiError(400, "领域知识单条长度不能超过 200 字，当前为 {$charCount} 字");
        }

        try {
            $created = \App\System\DomainKnowledgeManager::add($content, $enabled);
            AuditLogManager::record('ai.domain_knowledge.create', $created['id'], [
                'content' => mb_substr($content, 0, 50, 'UTF-8') . ($charCount > 50 ? '...' : ''),
                'enabled' => $enabled,
            ], true, 'admin', $this->getClientIp());

            return $this->respondSuccess($created, '创建已知领域知识条目成功');
        } catch (\Throwable $e) {
            throw new ApiError(400, $e->getMessage());
        }
    }

    #[PutMapping(path: 'ai/domain-knowledge/{id}')]
    public function updateDomainKnowledge(string $id): ResponseInterface
    {
        $body = $this->getParsedBody();
        $content = isset($body['content']) ? trim((string) $body['content']) : null;
        $enabled = isset($body['enabled']) ? (bool) $body['enabled'] : null;

        if ($content !== null) {
            if ($content === '') {
                throw new ApiError(400, '领域知识内容不能为空');
            }
            $charCount = mb_strlen($content, 'UTF-8');
            if ($charCount > \App\System\DomainKnowledgeManager::MAX_ITEM_LENGTH) {
                throw new ApiError(400, "领域知识单条长度不能超过 200 字，当前为 {$charCount} 字");
            }
        }

        try {
            $updated = \App\System\DomainKnowledgeManager::update($id, $content, $enabled);
            if ($updated === null) {
                throw new ApiError(404, '指定领域知识条目不存在');
            }

            AuditLogManager::record('ai.domain_knowledge.update', $id, [
                'content' => $content !== null ? mb_substr($content, 0, 50, 'UTF-8') . '...' : null,
                'enabled' => $enabled,
            ], true, 'admin', $this->getClientIp());

            return $this->respondSuccess($updated, '更新已知领域知识条目成功');
        } catch (ApiError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ApiError(400, $e->getMessage());
        }
    }

    #[DeleteMapping(path: 'ai/domain-knowledge/{id}')]
    public function deleteDomainKnowledge(string $id): ResponseInterface
    {
        $deleted = \App\System\DomainKnowledgeManager::delete($id);
        if (!$deleted) {
            throw new ApiError(404, '指定领域知识条目不存在');
        }

        AuditLogManager::record('ai.domain_knowledge.delete', $id, [], true, 'admin', $this->getClientIp());
        return $this->respondSuccess(['deleted' => true, 'id' => $id], '删除已知领域知识条目成功');
    }

    // ── LogAgent 分析记录管理（Analyses Management） ──

    #[GetMapping(path: 'ai/analyses')]
    public function listAnalyses(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $page = isset($params['page']) ? (int) $params['page'] : 1;
        $pageSize = isset($params['pageSize']) ? (int) $params['pageSize'] : (isset($params['limit']) ? (int) $params['limit'] : 20);

        $filters = [];
        if (isset($params['mode']) && $params['mode'] !== '') {
            $filters['mode'] = (string) $params['mode'];
        }
        if (isset($params['promptVersion']) && $params['promptVersion'] !== '') {
            $filters['promptVersion'] = (string) $params['promptVersion'];
        }
        if (isset($params['keyword']) && trim((string) $params['keyword']) !== '') {
            $filters['keyword'] = trim((string) $params['keyword']);
        }
        if (isset($params['minScore']) && is_numeric($params['minScore'])) {
            $filters['minScore'] = (float) $params['minScore'];
        }
        if (isset($params['maxScore']) && is_numeric($params['maxScore'])) {
            $filters['maxScore'] = (float) $params['maxScore'];
        }
        if (isset($params['success']) && $params['success'] !== '') {
            $filters['success'] = $params['success'];
        }
        if (isset($params['since']) && is_numeric($params['since'])) {
            $filters['since'] = (int) $params['since'];
        }
        if (isset($params['until']) && is_numeric($params['until'])) {
            $filters['until'] = (int) $params['until'];
        }

        $res = AnalysisRecordManager::list($filters, $page, $pageSize);
        return $this->respondSuccess($res, 'AI analysis records retrieved successfully');
    }

    #[GetMapping(path: 'ai/analyses/{cacheKey}')]
    public function getAnalysis(string $cacheKey): ResponseInterface
    {
        $record = AnalysisRecordManager::get($cacheKey);
        if ($record === null) {
            throw new ApiError(404, "Analysis record not found for key: {$cacheKey}");
        }

        return $this->respondSuccess($record, 'Analysis detail retrieved successfully');
    }

    #[GetMapping(path: 'ai/analyses/{cacheKey}/trace')]
    public function getAnalysisTrace(string $cacheKey): ResponseInterface
    {
        $trace = AnalysisRecordManager::getTrace($cacheKey);
        if ($trace === null) {
            throw new ApiError(404, "Analysis trace not found for key: {$cacheKey}");
        }

        return $this->respondSuccess($trace, 'Analysis trace retrieved successfully');
    }

    #[DeleteMapping(path: 'ai/analyses/{cacheKey}')]
    public function deleteAnalysis(string $cacheKey): ResponseInterface
    {
        $deleted = AnalysisRecordManager::delete($cacheKey);
        AuditLogManager::record('ai.analyses.delete', $cacheKey, [], $deleted, 'admin', $this->getClientIp());
        return $this->respondSuccess(['deleted' => $deleted, 'cacheKey' => $cacheKey], 'Analysis record deleted successfully');
    }

    // ── LogAgent 质量评分看板（Scoreboard） ──

    #[GetMapping(path: 'ai/scoreboard')]
    public function getScoreboard(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $days = isset($params['days']) && is_numeric($params['days']) ? (int) $params['days'] : 7;
        $scoreboard = AnalysisRecordManager::getScoreboard($days);
        return $this->respondSuccess($scoreboard, 'AI scoreboard retrieved successfully');
    }

    #[GetMapping(path: 'ai/scoreboard/slow')]
    public function getSlowAnalyses(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $thresholdSec = isset($params['thresholdSec']) && is_numeric($params['thresholdSec']) ? (float) $params['thresholdSec'] : 15.0;
        $limit = isset($params['limit']) && is_numeric($params['limit']) ? min(100, max(1, (int) $params['limit'])) : 20;

        $items = AnalysisRecordManager::getSlowAnalyses($limit, $thresholdSec * 1000.0);
        return $this->respondSuccess([
            'thresholdSec' => $thresholdSec,
            'limit' => $limit,
            'total' => count($items),
            'items' => $items,
        ], 'Slow analysis records retrieved successfully');
    }

    #[GetMapping(path: 'ai/scoreboard/low-score')]
    public function getLowScoreAnalyses(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $maxScore = isset($params['maxScore']) && is_numeric($params['maxScore']) ? (float) $params['maxScore'] : 60.0;
        $limit = isset($params['limit']) && is_numeric($params['limit']) ? min(100, max(1, (int) $params['limit'])) : 20;

        $items = AnalysisRecordManager::getLowScoreAnalyses($limit, $maxScore);
        return $this->respondSuccess([
            'maxScore' => $maxScore,
            'limit' => $limit,
            'total' => count($items),
            'items' => $items,
        ], 'Low-score analysis records retrieved successfully');
    }

    // ── Prompt 版本管理（Prompt Versioning） ──

    #[GetMapping(path: 'ai/prompts')]
    public function listPrompts(): ResponseInterface
    {
        $prompts = PromptManager::listPrompts();
        return $this->respondSuccess([
            'items' => $prompts,
            'total' => count($prompts),
        ], 'Prompt versions retrieved successfully');
    }

    #[GetMapping(path: 'ai/prompts/{promptVersion}')]
    public function getPrompt(string $promptVersion): ResponseInterface
    {
        $prompt = PromptManager::getPrompt($promptVersion);
        return $this->respondSuccess($prompt, 'Prompt version retrieved successfully');
    }

    #[PostMapping(path: 'ai/prompts')]
    public function createPrompt(): ResponseInterface
    {
        $body = $this->getParsedBody();
        $version = isset($body['version']) ? trim((string) $body['version']) : '';
        $content = isset($body['systemPrompt']) ? (string) $body['systemPrompt'] : (isset($body['content']) ? (string) $body['content'] : '');
        $forkFrom = isset($body['forkFrom']) && is_string($body['forkFrom']) ? trim($body['forkFrom']) : null;

        PromptManager::savePrompt($version, $content, true, $forkFrom);
        AuditLogManager::record('ai.prompts.create', $version, ['forkFrom' => $forkFrom], true, 'admin', $this->getClientIp());

        return $this->respondSuccess(PromptManager::getPrompt($version), 'Prompt version created successfully');
    }

    #[PutMapping(path: 'ai/prompts/{promptVersion}')]
    public function updatePrompt(string $promptVersion): ResponseInterface
    {
        $body = $this->getParsedBody();
        $content = isset($body['systemPrompt']) ? (string) $body['systemPrompt'] : (isset($body['content']) ? (string) $body['content'] : '');

        PromptManager::savePrompt($promptVersion, $content, false);
        AuditLogManager::record('ai.prompts.update', $promptVersion, ['length' => mb_strlen($content)], true, 'admin', $this->getClientIp());

        return $this->respondSuccess(PromptManager::getPrompt($promptVersion), 'Prompt version updated successfully');
    }

    #[DeleteMapping(path: 'ai/prompts/{promptVersion}')]
    public function deletePrompt(string $promptVersion): ResponseInterface
    {
        PromptManager::deletePrompt($promptVersion);
        AuditLogManager::record('ai.prompts.delete', $promptVersion, [], true, 'admin', $this->getClientIp());
        return $this->respondSuccess(['deleted' => true, 'version' => $promptVersion], 'Prompt version deleted successfully');
    }

    #[PostMapping(path: 'ai/prompts/{promptVersion}/activate')]
    public function activatePrompt(string $promptVersion): ResponseInterface
    {
        PromptManager::activatePrompt($promptVersion);
        AuditLogManager::record('ai.prompts.activate', $promptVersion, [], true, 'admin', $this->getClientIp());
        return $this->respondSuccess(['activated' => true, 'version' => $promptVersion], 'Prompt version activated successfully');
    }

    // ── 工具管理（Tool Management） ──

    #[GetMapping(path: 'ai/tools')]
    public function listAiTools(): ResponseInterface
    {
        $tools = ToolManager::listTools();
        return $this->respondSuccess([
            'items' => $tools,
            'total' => count($tools),
        ], 'Tools retrieved successfully');
    }

    #[PutMapping(path: 'ai/tools/{name}/enable')]
    public function setAiToolEnabled(string $name): ResponseInterface
    {
        $body = $this->getParsedBody();
        $enabled = isset($body['enabled']) ? (bool) $body['enabled'] : true;

        ToolManager::setToolEnabled($name, $enabled);
        AuditLogManager::record('ai.tools.enable', $name, ['enabled' => $enabled], true, 'admin', $this->getClientIp());

        return $this->respondSuccess([
            'name' => $name,
            'enabled' => $enabled,
        ], 'Tool status updated successfully');
    }

    #[PutMapping(path: 'ai/tools/{name}/config')]
    public function updateAiToolConfig(string $name): ResponseInterface
    {
        $body = $this->getParsedBody();
        ToolManager::updateToolConfig($name, $body);
        AuditLogManager::record('ai.tools.config', $name, $body, true, 'admin', $this->getClientIp());

        return $this->respondSuccess([
            'name' => $name,
            'updated' => true,
        ], 'Tool configuration updated successfully');
    }

    // ── 分析模式配置（Mode Limits Management） ──

    #[GetMapping(path: 'ai/modes')]
    public function listAiModes(): ResponseInterface
    {
        return $this->respondSuccess(AnalysisMode::allModes(), 'Analysis modes retrieved successfully');
    }

    #[PutMapping(path: 'ai/modes/{mode}')]
    public function updateAiMode(string $mode): ResponseInterface
    {
        $validModes = [AnalysisMode::QUICK, AnalysisMode::DEEP, AnalysisMode::LAUNCHER];
        if (!in_array($mode, $validModes, true)) {
            throw new ApiError(400, "Invalid analysis mode: {$mode}");
        }

        $body = $this->getParsedBody();
        $rawDynamic = Config::getDynamicConfigRaw();
        $modes = $rawDynamic['ai']['agent']['modes'] ?? (Config::Get('ai')['agent']['modes'] ?? []);
        if (!is_array($modes)) {
            $modes = [];
        }

        $currentLimits = AnalysisMode::limits($mode);
        $fields = [
            'maxRounds' => 'int',
            'maxWebSearch' => 'int',
            'maxTotalRetrieval' => 'int',
            'maxFileReads' => 'int',
            'maxLlmCalls' => 'int',
            'allowWebSearch' => 'bool',
            'allowRag' => 'bool',
            'allowGithub' => 'bool',
            'allowLogFiles' => 'bool',
        ];

        $updatedMode = $modes[$mode] ?? $currentLimits;
        foreach ($fields as $field => $type) {
            if (isset($body[$field])) {
                if ($type === 'int') {
                    $updatedMode[$field] = max(0, min(100, (int) $body[$field]));
                } else {
                    $updatedMode[$field] = (bool) $body[$field];
                }
            }
        }

        $modes[$mode] = $updatedMode;
        $agent = $rawDynamic['ai']['agent'] ?? (Config::Get('ai')['agent'] ?? []);
        if (!is_array($agent)) {
            $agent = [];
        }
        $agent['modes'] = $modes;

        $update = [
            'ai' => [
                'agent' => $agent,
            ],
        ];

        Config::saveDynamic($update);
        Config::touchDynamicConfig();

        AuditLogManager::record('ai.modes.update', $mode, $updatedMode, true, 'admin', $this->getClientIp());

        return $this->respondSuccess([
            'mode' => $mode,
            'limits' => AnalysisMode::limits($mode),
        ], 'Analysis mode limits updated successfully');
    }

    #[GetMapping(path: 'system/stats')]
    public function getSystemStats(): ResponseInterface
    {
        $storageConfig = Config::Get('storage');
        $storageId = $storageConfig['storageId'] ?? 's';
        $storage = $this->getStorageClass();

        $totalLogs = 0;
        try {
            $totalLogs = $storage::Count();
        } catch (\Throwable) {
            // Database might be temporarily unavailable
        }

        $ragDbPath = \App\Rag\RagSearch::resolveDbPath();
        $ragIndexMtime = file_exists($ragDbPath) ? filemtime($ragDbPath) : null;

        return $this->respondSuccess([
            'version' => Version::VERSION,
            'phpVersion' => PHP_VERSION,
            'swooleVersion' => phpversion('swoole') ?: null,
            'storageBackend' => $storageId,
            'totalLogs' => $totalLogs,
            'storageTime' => (int) ($storageConfig['storageTime'] ?? 0),
            'aiEnabled' => (bool) (Config::Get('ai')['enabled'] ?? false),
            'ragIndexUpdated' => $ragIndexMtime,
        ], 'System statistics retrieved successfully');
    }

    #[GetMapping(path: 'config')]
    public function getConfig(): ResponseInterface
    {
        return $this->respondSuccess(Config::getMasked(), 'Configuration retrieved successfully');
    }

    #[PutMapping(path: 'config')]
    public function updateConfig(): ResponseInterface
    {
        $payload = $this->getParsedBody();
        if (empty($payload)) {
            throw new ApiError(400, 'Invalid or empty configuration payload');
        }

        try {
            Config::saveDynamic($payload);
            AuditLogManager::record('config.update', 'dynamic_config', [
                'keys' => array_keys($payload),
            ], true, 'admin', $this->getClientIp());
        } catch (\InvalidArgumentException $e) {
            throw new ApiError(422, 'Configuration validation failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            throw new ApiError(500, 'Failed to save dynamic configuration: ' . $e->getMessage());
        }

        return $this->respondSuccess(Config::getMasked(), 'Configuration updated successfully');
    }

    #[PostMapping(path: 'config/reset')]
    public function resetConfig(): ResponseInterface
    {
        try {
            Config::resetDynamic();
            AuditLogManager::record('config.reset', 'dynamic_config', [], true, 'admin', $this->getClientIp());
        } catch (\Throwable $e) {
            throw new ApiError(500, 'Failed to reset configuration: ' . $e->getMessage());
        }

        return $this->respondSuccess(Config::getMasked(), 'Configuration reset to defaults successfully');
    }

    #[PostMapping(path: 'config/test-ai')]
    public function testAiConnection(): ResponseInterface
    {
        $body = $this->getParsedBody();
        $rawConfig = Config::all();
        $aiConfig = $rawConfig['ai'] ?? [];

        $baseUrl = trim((string) ($body['baseUrl'] ?? ($aiConfig['baseUrl'] ?? '')));
        $model = trim((string) ($body['model'] ?? ($aiConfig['model'] ?? '')));
        $apiKey = trim((string) ($body['apiKey'] ?? ''));

        if ($apiKey === '' || str_contains($apiKey, '****') || $apiKey === '********') {
            $apiKeys = $aiConfig['apiKeys'] ?? [];
            $apiKey = (string) ($apiKeys[0] ?? '');
        }

        $timeout = min(60, max(2, (int) ($body['timeout'] ?? ($aiConfig['timeout'] ?? 15))));

        if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new ApiError(400, 'A valid baseUrl is required for AI connection test');
        }
        if ($model === '') {
            throw new ApiError(400, 'Model is required for AI connection test');
        }
        if ($apiKey === '') {
            throw new ApiError(400, 'API key is required for AI connection test');
        }

        $endpoint = rtrim($baseUrl, '/') . '/chat/completions';
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => 'ping'],
            ],
            'max_tokens' => 5,
        ];

        $headersInput = $body['headers'] ?? ($aiConfig['headers'] ?? []);
        $httpHeaders = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        if (is_array($headersInput)) {
            foreach ($headersInput as $k => $v) {
                if (is_string($k) && $k !== '') {
                    $headerLine = $k . ': ' . (string) $v;
                    $keyLower = strtolower($k);
                    $overridden = false;
                    foreach ($httpHeaders as $idx => $existing) {
                        if (str_starts_with(strtolower($existing), $keyLower . ':')) {
                            $httpHeaders[$idx] = $headerLine;
                            $overridden = true;
                            break;
                        }
                    }
                    if (!$overridden) {
                        $httpHeaders[] = $headerLine;
                    }
                } elseif (is_string($v) && trim($v) !== '') {
                    $httpHeaders[] = trim($v);
                }
            }
        }

        $start = microtime(true);
        $ch = curl_init();
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => $httpHeaders,
                CURLOPT_FORBID_REUSE => true,
            ]);

            $response = curl_exec($ch);
            $latencyMs = (int) round((microtime(true) - $start) * 1000);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($curlError !== '') {
                return $this->respondSuccess([
                    'success' => false,
                    'latencyMs' => $latencyMs,
                    'httpCode' => $httpCode,
                    'error' => 'cURL error: ' . $curlError,
                ], 'AI connection test failed');
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                return $this->respondSuccess([
                    'success' => true,
                    'latencyMs' => $latencyMs,
                    'httpCode' => $httpCode,
                    'model' => $model,
                ], 'AI connection test succeeded');
            }

            $decoded = is_string($response) ? json_decode($response, true) : null;
            $msg = is_array($decoded) && isset($decoded['error']['message'])
                ? (string) $decoded['error']['message']
                : (is_string($response) ? substr($response, 0, 200) : 'HTTP ' . $httpCode);

            return $this->respondSuccess([
                'success' => false,
                'latencyMs' => $latencyMs,
                'httpCode' => $httpCode,
                'error' => $msg,
            ], 'AI connection returned non-2xx status');
        } finally {
            if ($ch instanceof \CurlHandle) {
                curl_close($ch);
            }
            $ch = null;
        }
    }

    #[PostMapping(path: 'config/test-rag-provider')]
    public function testRagProvider(): ResponseInterface
    {
        $body = $this->getParsedBody();
        $rawConfig = Config::all();
        $providers = $rawConfig['ai']['rag']['providers'] ?? [];

        $baseUrl = trim((string) ($body['baseUrl'] ?? ''));
        $apiKey = trim((string) ($body['apiKey'] ?? ''));
        $embeddingModel = trim((string) ($body['embeddingModel'] ?? ''));
        $providerName = trim((string) ($body['name'] ?? ''));

        if ($apiKey === '' || str_contains($apiKey, '****') || $apiKey === '********') {
            if (is_array($providers)) {
                foreach ($providers as $p) {
                    if (is_array($p) && (($providerName !== '' && ($p['name'] ?? '') === $providerName) || ($baseUrl !== '' && ($p['baseUrl'] ?? '') === $baseUrl))) {
                        $apiKey = (string) ($p['apiKey'] ?? '');
                        if ($embeddingModel === '') {
                            $embeddingModel = (string) ($p['embeddingModel'] ?? '');
                        }
                        break;
                    }
                }
            }
        }

        if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new ApiError(400, 'A valid baseUrl is required for RAG provider test');
        }
        if ($embeddingModel === '') {
            throw new ApiError(400, 'embeddingModel is required for RAG provider test');
        }
        if ($apiKey === '') {
            throw new ApiError(400, 'API key is required for RAG provider test');
        }

        $endpoint = rtrim($baseUrl, '/') . '/embeddings';
        $payload = [
            'model' => $embeddingModel,
            'input' => ['Hello world test embedding'],
        ];

        $start = microtime(true);
        $ch = curl_init();
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_FORBID_REUSE => true,
            ]);

            $response = curl_exec($ch);
            $latencyMs = (int) round((microtime(true) - $start) * 1000);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if ($curlError !== '') {
                return $this->respondSuccess([
                    'success' => false,
                    'latencyMs' => $latencyMs,
                    'httpCode' => $httpCode,
                    'error' => 'cURL error: ' . $curlError,
                ], 'Embedding provider test failed');
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                $decoded = is_string($response) ? json_decode($response, true) : null;
                $dim = isset($decoded['data'][0]['embedding']) && is_array($decoded['data'][0]['embedding'])
                    ? count($decoded['data'][0]['embedding'])
                    : null;

                return $this->respondSuccess([
                    'success' => true,
                    'latencyMs' => $latencyMs,
                    'httpCode' => $httpCode,
                    'dimension' => $dim,
                    'model' => $embeddingModel,
                ], 'Embedding provider responded successfully');
            }

            $decoded = is_string($response) ? json_decode($response, true) : null;
            $msg = is_array($decoded) && isset($decoded['error']['message'])
                ? (string) $decoded['error']['message']
                : (is_string($response) ? substr($response, 0, 200) : 'HTTP ' . $httpCode);

            return $this->respondSuccess([
                'success' => false,
                'latencyMs' => $latencyMs,
                'httpCode' => $httpCode,
                'error' => $msg,
            ], 'Embedding provider returned non-2xx status');
        } finally {
            if ($ch instanceof \CurlHandle) {
                curl_close($ch);
            }
            $ch = null;
        }
    }

    #[GetMapping(path: 'rag/stats')]
    public function getRagStats(): ResponseInterface
    {
        return $this->respondSuccess(RagManager::getStats(), 'RAG statistics retrieved successfully');
    }

    #[PostMapping(path: 'rag/build')]
    public function triggerRagBuild(): ResponseInterface
    {
        $result = RagManager::triggerBuild();
        return $this->respondSuccess($result, $result['message']);
    }

    #[GetMapping(path: 'rag/build/status')]
    public function getRagBuildStatus(): ResponseInterface
    {
        return $this->respondSuccess(RagManager::getBuildStatus(), 'RAG build status retrieved successfully');
    }

    /**
     * RAG 能力开关快照（chunker / rerank / queryRewrite / incrementalBuild /
     * semanticCache / telemetry）。只读，供管理面板渲染当前生效配置。
     */
    #[GetMapping(path: 'rag/config')]
    public function getRagConfig(): ResponseInterface
    {
        $rag = Config::all()['ai']['rag'] ?? [];
        return $this->respondSuccess([
            'chunker' => (string) ($rag['chunker'] ?? 'heading'),
            'rerank' => [
                'enabled' => (bool) ($rag['rerank']['enabled'] ?? false),
                'maxCandidates' => (int) ($rag['rerank']['maxCandidates'] ?? 30),
            ],
            'queryRewrite' => [
                'enabled' => (bool) ($rag['queryRewrite']['enabled'] ?? false),
            ],
            'incrementalBuild' => (bool) ($rag['incrementalBuild'] ?? false),
            'semanticCache' => (bool) ($rag['semanticCache'] ?? true),
            'telemetry' => [
                'enabled' => (bool) ($rag['telemetry']['enabled'] ?? true),
                'slowMs' => (int) ($rag['telemetry']['slowMs'] ?? 500),
            ],
        ], 'RAG capability switches retrieved successfully');
    }

    /**
     * 更新 RAG 能力开关。走 Config::saveDynamic 白名单式局部合并，只接受
     * 已知字段并做取值校验；chunker 变更后需重跑 rag:build 才影响索引。
     */
    #[PutMapping(path: 'rag/config')]
    public function updateRagConfig(): ResponseInterface
    {
        $body = $this->getParsedBody();
        if (empty($body)) {
            throw new ApiError(400, 'Invalid or empty RAG config payload');
        }

        $ragUpdate = [];
        if (isset($body['chunker'])) {
            $chunker = strtolower(trim((string) $body['chunker']));
            if (\App\Rag\ChunkStrategy::tryFrom($chunker) === null) {
                throw new ApiError(422, 'Invalid chunker; expected heading|sliding|token|hybrid');
            }
            $ragUpdate['chunker'] = $chunker;
        }
        if (isset($body['rerank']) && is_array($body['rerank'])) {
            $ragUpdate['rerank'] = [
                'enabled' => (bool) ($body['rerank']['enabled'] ?? false),
                'maxCandidates' => max(2, min(50, (int) ($body['rerank']['maxCandidates'] ?? 30))),
            ];
        }
        if (isset($body['queryRewrite']) && is_array($body['queryRewrite'])) {
            $ragUpdate['queryRewrite'] = ['enabled' => (bool) ($body['queryRewrite']['enabled'] ?? false)];
        }
        if (array_key_exists('incrementalBuild', $body)) {
            $ragUpdate['incrementalBuild'] = (bool) $body['incrementalBuild'];
        }
        if (array_key_exists('semanticCache', $body)) {
            $ragUpdate['semanticCache'] = (bool) $body['semanticCache'];
        }
        if (isset($body['telemetry']) && is_array($body['telemetry'])) {
            $ragUpdate['telemetry'] = [
                'enabled' => (bool) ($body['telemetry']['enabled'] ?? true),
                'slowMs' => max(1, (int) ($body['telemetry']['slowMs'] ?? 500)),
            ];
        }

        if ($ragUpdate === []) {
            throw new ApiError(400, 'No recognized RAG switch provided');
        }

        try {
            Config::saveDynamic(['ai' => ['rag' => $ragUpdate]]);
            AuditLogManager::record('config.update', 'rag_config', [
                'keys' => array_keys($ragUpdate),
            ], true, 'admin', $this->getClientIp());
        } catch (\InvalidArgumentException $e) {
            throw new ApiError(422, 'RAG config validation failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            throw new ApiError(500, 'Failed to save RAG config: ' . $e->getMessage());
        }

        return $this->getRagConfig();
    }

    /**
     * 增量索引预览：返回知识库目录相对索引的 changed/missing/unchanged 清单，
     * 不触发写入。索引尚未构建时返回 409。
     */
    #[GetMapping(path: 'rag/build/stale')]
    public function getRagStaleFiles(): ResponseInterface
    {
        try {
            $stale = RagManager::getStaleFiles();
        } catch (\RuntimeException $e) {
            throw new ApiError(409, $e->getMessage());
        } catch (\Throwable $e) {
            throw new ApiError(500, 'Failed to diff knowledge base: ' . $e->getMessage());
        }
        return $this->respondSuccess($stale, 'RAG stale files retrieved successfully');
    }

    /**
     * 触发增量构建（仅重索引 mtime 变化的文件）。与全量 rag/build 共用状态
     * 文件与 building 防重入锁；返回触发结果，进度由 rag/build/status 轮询。
     */
    #[PostMapping(path: 'rag/build/incremental')]
    public function triggerRagIncrementalBuild(): ResponseInterface
    {
        $result = RagManager::triggerIncrementalBuild();
        AuditLogManager::record('rag.build.incremental', 'rag_index', [
            'success' => $result['success'],
        ], true, 'admin', $this->getClientIp());
        return $this->respondSuccess($result, $result['message']);
    }

    /**
     * 检索遥测：当日（或 ?date=Y-m-d）各阶段耗时/命中聚合 + ?slow=1 慢查询明细。
     */
    #[GetMapping(path: 'rag/telemetry')]
    public function getRagTelemetry(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $date = isset($params['date']) && is_string($params['date']) ? trim($params['date']) : null;
        if ($date !== null && $date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ApiError(400, 'date must be formatted as Y-m-d');
        }

        $summary = \App\Rag\RetrievalMetrics::summary($date !== '' ? $date : null);
        $slow = !empty($params['slow'])
            ? \App\Rag\RetrievalMetrics::recentSlow(max(1, min(100, (int) ($params['slowLimit'] ?? 10))))
            : [];

        return $this->respondSuccess([
            'date' => $date !== null && $date !== '' ? $date : date('Y-m-d'),
            'summary' => $summary,
            'available' => $summary !== null,
            'slowQueries' => $slow,
        ], 'RAG telemetry retrieved successfully');
    }

    #[PostMapping(path: 'rag/search')]
    public function searchRag(): ResponseInterface
    {
        $body = $this->getParsedBody();
        $query = trim((string) ($body['query'] ?? ''));
        $limit = min(20, max(1, (int) ($body['limit'] ?? 5)));

        if ($query === '') {
            throw new ApiError(400, 'Query parameter is required for RAG search');
        }

        $results = RagManager::search($query, $limit);
        return $this->respondSuccess([
            'query' => $query,
            'count' => count($results),
            'items' => $results,
        ], 'RAG search executed successfully');
    }

    #[GetMapping(path: 'rag/topics')]
    public function getRagTopics(): ResponseInterface
    {
        $stats = RagManager::listDocs();
        return $this->respondSuccess([
            'topics' => $stats['topics'],
            'totalTopics' => count($stats['topics']),
        ], 'RAG topics retrieved successfully');
    }

    #[GetMapping(path: 'rag/docs')]
    public function getRagDocs(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $topic = isset($params['topic']) && is_string($params['topic']) ? trim($params['topic']) : null;
        $keyword = isset($params['keyword']) && is_string($params['keyword']) ? trim($params['keyword']) : null;

        $data = RagManager::listDocs($topic, $keyword);
        return $this->respondSuccess($data, 'RAG documents retrieved successfully');
    }

    #[GetMapping(path: 'rag/docs/content')]
    public function getRagDocContent(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $path = trim((string) ($params['path'] ?? ''));
        if ($path === '') {
            throw new ApiError(400, 'Path query parameter is required');
        }

        try {
            $doc = RagManager::readDoc($path);
        } catch (\InvalidArgumentException $e) {
            throw new ApiError(400, $e->getMessage());
        } catch (\Throwable $e) {
            throw new ApiError(500, 'Failed to read document: ' . $e->getMessage());
        }

        return $this->respondSuccess($doc, 'RAG document content retrieved successfully');
    }

    #[PostMapping(path: 'rag/docs/save')]
    public function saveRagDoc(): ResponseInterface
    {
        $body = $this->getParsedBody();
        if (empty($body)) {
            throw new ApiError(400, 'Invalid request body');
        }

        $content = (string) ($body['content'] ?? '');
        $topic = trim((string) ($body['topic'] ?? ''));
        $filename = trim((string) ($body['filename'] ?? ''));
        $path = trim((string) ($body['path'] ?? ''));
        $isNew = (bool) ($body['isNew'] ?? false);

        if ($path !== '') {
            $parts = explode('/', str_replace('\\', '/', $path));
            if (count($parts) >= 2) {
                $topic = $parts[0];
                $filename = end($parts);
            }
        }

        if ($topic === '' || $filename === '') {
            throw new ApiError(400, 'Both topic and filename are required to save a document');
        }

        try {
            $saved = RagManager::saveDoc($topic, $filename, $content, $isNew);
        } catch (\InvalidArgumentException $e) {
            throw new ApiError(400, $e->getMessage());
        } catch (\Throwable $e) {
            throw new ApiError(500, 'Failed to save document: ' . $e->getMessage());
        }

        return $this->respondSuccess($saved, 'RAG document saved successfully. Click "Rebuild Index" to refresh the vector search.');
    }

    #[DeleteMapping(path: 'rag/docs')]
    #[PostMapping(path: 'rag/docs/delete')]
    public function deleteRagDoc(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $body = $this->getParsedBody();
        $path = trim((string) ($params['path'] ?? ($body['path'] ?? '')));

        if ($path === '') {
            throw new ApiError(400, 'Path parameter is required to delete a document');
        }

        try {
            $result = RagManager::deleteDoc($path);
        } catch (\InvalidArgumentException $e) {
            throw new ApiError(400, $e->getMessage());
        } catch (\Throwable $e) {
            throw new ApiError(500, 'Failed to delete document: ' . $e->getMessage());
        }

        return $this->respondSuccess($result, 'RAG document deleted successfully');
    }

    #[PostMapping(path: 'rag/docs/upload')]
    public function uploadRagDoc(): ResponseInterface
    {
        $topic = trim((string) ($this->request->input('topic') ?? ''));
        $body = $this->getParsedBody();
        if (!empty($body) && $topic === '') {
            $topic = trim((string) ($body['topic'] ?? ''));
        }

        if ($topic === '') {
            throw new ApiError(400, 'Topic directory is required for upload');
        }

        // 1. Multipart form file upload
        if ($this->request->hasFile('file')) {
            $file = $this->request->file('file');
            if ($file === null || (method_exists($file, 'isValid') && !$file->isValid())) {
                throw new ApiError(400, 'Uploaded file is invalid or corrupted');
            }

            $origName = $file->getClientFilename() ?: 'uploaded_doc.md';
            $customName = trim((string) ($this->request->input('filename') ?? ''));
            $filename = $customName !== '' ? $customName : $origName;
            $stream = (string) $file->getStream();

            try {
                $saved = RagManager::saveDoc($topic, $filename, $stream, false);
            } catch (\InvalidArgumentException $e) {
                throw new ApiError(400, $e->getMessage());
            } catch (\Throwable $e) {
                throw new ApiError(500, 'Failed to process uploaded file: ' . $e->getMessage());
            }

            return $this->respondSuccess($saved, 'Document uploaded successfully. Click "Rebuild Index" to refresh the vector search.');
        }

        // 2. Direct JSON payload upload (topic + filename + content)
        if (isset($body['content'])) {
            $filename = trim((string) ($body['filename'] ?? 'uploaded_doc.md'));
            $content = (string) $body['content'];

            try {
                $saved = RagManager::saveDoc($topic, $filename, $content, false);
            } catch (\InvalidArgumentException $e) {
                throw new ApiError(400, $e->getMessage());
            } catch (\Throwable $e) {
                throw new ApiError(500, 'Failed to process document content: ' . $e->getMessage());
            }

            return $this->respondSuccess($saved, 'Document uploaded successfully. Click "Rebuild Index" to refresh the vector search.');
        }

        throw new ApiError(400, 'No file or content provided for upload');
    }

    #[GetMapping(path: 'telemetry/stats')]
    public function getTelemetryStats(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $day = isset($params['day']) && is_string($params['day']) && $params['day'] !== '' ? $params['day'] : null;
        $stats = TelemetryService::getStats($day);
        return $this->respondSuccess($stats);
    }

    #[DeleteMapping(path: 'telemetry/stats')]
    public function clearTelemetryStats(): ResponseInterface
    {
        $cleared = TelemetryService::clearStats();
        return $this->respondSuccess(['cleared' => $cleared], 'Telemetry stats cleared');
    }

    #[GetMapping(path: 'system/logs')]
    public function getSystemLogs(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $limit = isset($params['limit']) && is_numeric($params['limit']) ? (int) $params['limit'] : 100;
        $level = isset($params['level']) && is_string($params['level']) && $params['level'] !== '' ? $params['level'] : null;
        $keyword = isset($params['keyword']) && is_string($params['keyword']) && $params['keyword'] !== '' ? $params['keyword'] : null;
        $since = isset($params['since']) && is_numeric($params['since']) ? (int) $params['since'] : null;

        $logs = SystemLogManager::getLogs($limit, $level, $keyword, $since);
        return $this->respondSuccess($logs);
    }

    #[DeleteMapping(path: 'system/logs')]
    public function clearSystemLogs(): ResponseInterface
    {
        $cleared = SystemLogManager::clearLogs();
        return $this->respondSuccess(['cleared' => $cleared], 'System logs cleared');
    }

    #[GetMapping(path: 'analytics/sources')]
    public function getAnalyticsSources(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $days = isset($params['days']) && is_numeric($params['days']) ? (int) $params['days'] : 7;
        $stats = AnalyticsService::getSourceStats($days);
        return $this->respondSuccess($stats, 'Source distribution retrieved successfully');
    }

    #[GetMapping(path: 'analytics/versions')]
    public function getAnalyticsVersions(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $days = isset($params['days']) && is_numeric($params['days']) ? (int) $params['days'] : 30;
        $stats = AnalyticsService::getVersionStats($days);
        return $this->respondSuccess($stats, 'Version statistics retrieved successfully');
    }

    #[GetMapping(path: 'analytics/trends')]
    public function getAnalyticsTrends(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $days = isset($params['days']) && is_numeric($params['days']) ? (int) $params['days'] : 7;
        $trends = AnalyticsService::getTrends($days);
        return $this->respondSuccess($trends, 'Log trends retrieved successfully');
    }

    #[GetMapping(path: 'system/storage-health')]
    public function getStorageHealth(): ResponseInterface
    {
        $health = StorageHealthService::getHealth();
        return $this->respondSuccess($health, 'Storage health diagnostics retrieved successfully');
    }

    #[PostMapping(path: 'system/cleanup-expired')]
    public function cleanupExpiredLogs(): ResponseInterface
    {
        try {
            $result = StorageHealthService::cleanupExpired();
            AuditLogManager::record('system.cleanup_expired', 'logs', $result, true, 'admin', $this->getClientIp());
            return $this->respondSuccess($result, "Cleanup completed: {$result['deletedCount']} logs removed");
        } catch (\Throwable $e) {
            AuditLogManager::record('system.cleanup_expired', 'logs', ['error' => $e->getMessage()], false, 'admin', $this->getClientIp());
            throw new ApiError(500, 'Failed to execute expired log cleanup: ' . $e->getMessage());
        }
    }

    #[PostMapping(path: 'system/cache/flush')]
    public function flushCache(): ResponseInterface
    {
        $body = $this->getParsedBody();
        $prefix = isset($body['prefix']) && is_string($body['prefix']) ? trim($body['prefix']) : 'log:*';
        $result = StorageHealthService::flushCache($prefix);
        AuditLogManager::record('system.cache_flush', $prefix, $result, true, 'admin', $this->getClientIp());
        return $this->respondSuccess($result, "Cache flush executed for prefix: {$prefix}");
    }

    #[PostMapping(path: 'logs/batch-delete')]
    public function batchDeleteLogs(): ResponseInterface
    {
        $body = $this->getParsedBody();
        if (empty($body)) {
            throw new ApiError(400, 'Invalid request body');
        }

        $source = isset($body['source']) && is_string($body['source']) && trim($body['source']) !== '' ? trim($body['source']) : null;
        $since = isset($body['since']) && is_numeric($body['since']) ? (int) $body['since'] : null;
        $until = isset($body['until']) && is_numeric($body['until']) ? (int) $body['until'] : null;
        $keyword = isset($body['keyword']) && is_string($body['keyword']) && trim($body['keyword']) !== '' ? trim($body['keyword']) : null;
        $limit = isset($body['limit']) && is_numeric($body['limit']) ? min(1000, max(1, (int) $body['limit'])) : 500;

        if ($source === null && $since === null && $until === null && $keyword === null) {
            throw new ApiError(400, 'At least one filter condition (source, since, until, or keyword) is required for batch deletion');
        }

        $storage = $this->getStorageClass();
        $items = $storage::List($limit, 0, $source, $since, $until, $keyword);
        $totalMatched = $storage::Count($source, $since, $until, $keyword);

        $deleted = [];
        $failed = [];

        foreach ($items as $item) {
            $logId = (string) $item['id'];
            try {
                $parsedId = new Id($logId);
                $log = new Log($parsedId);
                if ($log->exists() && $log->delete()) {
                    $deleted[] = $logId;
                } else {
                    $failed[] = $logId;
                }
            } catch (\Throwable) {
                $failed[] = $logId;
            }
        }

        AuditLogManager::record('log.batch_delete', 'batch', [
            'deletedCount' => count($deleted),
            'failedCount' => count($failed),
            'totalMatched' => $totalMatched,
            'criteria' => [
                'source' => $source,
                'since' => $since,
                'until' => $until,
                'keyword' => $keyword,
                'limit' => $limit,
            ],
        ], true, 'admin', $this->getClientIp());

        return $this->respondSuccess([
            'deletedCount' => count($deleted),
            'failedCount' => count($failed),
            'totalMatched' => $totalMatched,
            'remaining' => max(0, $totalMatched - count($deleted)),
        ], 'Batch deletion completed');
    }

    #[GetMapping(path: 'security/bans')]
    public function getSecurityBans(): ResponseInterface
    {
        $bans = SecurityService::getBannedIps();
        return $this->respondSuccess([
            'bans' => $bans,
            'total' => count($bans),
        ], 'Banned IP list retrieved successfully');
    }

    #[PostMapping(path: 'security/ban')]
    public function banIp(): ResponseInterface
    {
        $body = $this->getParsedBody();
        if (empty($body['ip'])) {
            throw new ApiError(400, 'IP address is required');
        }

        $ip = trim((string) $body['ip']);
        $ttl = isset($body['ttl']) && is_numeric($body['ttl']) ? (int) $body['ttl'] : 86400;
        $reason = isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : '管理员主动封禁';

        $result = SecurityService::banIp($ip, $ttl, $reason);
        AuditLogManager::record('security.ban', $ip, [
            'ttl' => $ttl,
            'reason' => $reason,
        ], true, 'admin', $this->getClientIp());
        return $this->respondSuccess($result, "IP [{$ip}] successfully banned");
    }

    #[PostMapping(path: 'security/unban')]
    public function unbanIp(): ResponseInterface
    {
        $body = $this->getParsedBody();
        if (empty($body['ip'])) {
            throw new ApiError(400, 'IP address is required');
        }

        $ip = trim((string) $body['ip']);
        $success = SecurityService::unbanIp($ip);
        AuditLogManager::record('security.unban', $ip, [
            'unbanned' => $success,
        ], true, 'admin', $this->getClientIp());

        return $this->respondSuccess([
            'ip' => $ip,
            'unbanned' => $success,
        ], "IP [{$ip}] unbanned successfully");
    }

    #[GetMapping(path: 'security/overview')]
    public function getSecurityOverview(): ResponseInterface
    {
        $overview = SecurityService::getWafOverview();
        return $this->respondSuccess($overview, 'Security overview retrieved successfully');
    }

    #[GetMapping(path: 'security/content-rules')]
    public function getContentRules(): ResponseInterface
    {
        $rules = SecurityService::getContentRules();
        return $this->respondSuccess($rules, 'Content reject rules retrieved successfully');
    }

    #[PutMapping(path: 'security/content-rules')]
    public function updateContentRules(): ResponseInterface
    {
        $body = $this->getParsedBody();
        if (empty($body)) {
            throw new ApiError(400, 'Invalid request body');
        }

        $rules = SecurityService::saveContentRules($body);
        AuditLogManager::record('security.content_rules_update', 'rules', [
            'enabled' => $rules['enabled'],
            'keywordCount' => count($rules['keywords']),
            'patternCount' => count($rules['patterns']),
        ], true, 'admin', $this->getClientIp());
        return $this->respondSuccess($rules, 'Content reject rules updated successfully');
    }

    #[GetMapping(path: 'spinyarn/status')]
    public function getSpinYarnStatus(): ResponseInterface
    {
        $status = SpinYarnManager::getStatus();
        return $this->respondSuccess($status, 'SpinYarn status retrieved successfully');
    }

    #[PostMapping(path: 'spinyarn/test')]
    public function testSpinYarnDeobfuscate(): ResponseInterface
    {
        $body = $this->getParsedBody();
        if (empty($body['content']) || empty($body['version'])) {
            throw new ApiError(400, 'Both content and version are required');
        }

        $content = (string) $body['content'];
        $version = trim((string) $body['version']);
        $mappingType = isset($body['mappingType']) && $body['mappingType'] === 'vanilla' ? 'vanilla' : 'yarn';

        $result = SpinYarnManager::testDeobfuscate($content, $version, $mappingType);
        return $this->respondSuccess($result, 'SpinYarn deobfuscation test completed');
    }

    #[GetMapping(path: 'audit/logs')]
    public function getAuditLogs(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $page = isset($params['page']) ? (int) $params['page'] : 1;
        $pageSize = isset($params['pageSize']) ? (int) $params['pageSize'] : 20;
        $action = isset($params['action']) && $params['action'] !== '' ? (string) $params['action'] : null;
        $keyword = isset($params['keyword']) && $params['keyword'] !== '' ? (string) $params['keyword'] : null;
        $since = isset($params['since']) && is_numeric($params['since']) ? (int) $params['since'] : null;
        $until = isset($params['until']) && is_numeric($params['until']) ? (int) $params['until'] : null;

        $logs = AuditLogManager::getLogs($page, $pageSize, $action, $keyword, $since, $until);
        return $this->respondSuccess($logs, 'Audit logs retrieved successfully');
    }

    #[DeleteMapping(path: 'audit/logs')]
    public function clearAuditLogs(): ResponseInterface
    {
        $count = AuditLogManager::clearLogs();
        return $this->respondSuccess(['cleared' => $count], 'Audit logs cleared');
    }

    #[GetMapping(path: 'event-queue/stats')]
    public function getEventQueueStats(): ResponseInterface
    {
        $stats = \App\Queue\EventQueue::getStats();
        return $this->respondSuccess($stats, 'Event queue stats retrieved successfully');
    }

    #[GetMapping(path: 'event-queue/dead')]
    public function getDeadLetters(): ResponseInterface
    {
        $params = $this->request->getQueryParams();
        $limit = isset($params['limit']) && is_numeric($params['limit']) ? min(100, max(1, (int) $params['limit'])) : 50;
        $items = \App\Queue\DeadLetterQueue::list($limit);
        return $this->respondSuccess([
            'total' => \App\Queue\DeadLetterQueue::count(),
            'items' => $items,
        ], 'Dead letters retrieved successfully');
    }

    #[PostMapping(path: 'event-queue/dead/retry')]
    public function retryDeadLetter(): ResponseInterface
    {
        $body = $this->getParsedBody();
        $streamId = isset($body['streamId']) && is_string($body['streamId']) ? trim($body['streamId']) : null;
        if (empty($streamId)) {
            throw new ApiError(400, 'Parameter streamId is required');
        }

        $retried = \App\Queue\DeadLetterQueue::retry($streamId);
        if (!$retried) {
            throw new ApiError(404, 'Failed to retry dead letter: item not found or invalid');
        }

        AuditLogManager::record('event_queue.dead_retry', $streamId, [], true, 'admin', $this->getClientIp());
        return $this->respondSuccess(['streamId' => $streamId, 'retried' => true], 'Dead letter retried successfully');
    }

    #[DeleteMapping(path: 'event-queue/dead')]
    public function clearDeadLetters(): ResponseInterface
    {
        $cleared = \App\Queue\DeadLetterQueue::clear();
        AuditLogManager::record('event_queue.dead_clear', 'dead_letters', [], $cleared, 'admin', $this->getClientIp());
        return $this->respondSuccess(['cleared' => $cleared], 'Dead letters cleared');
    }

    private function getClientIp(): string
    {
        return $this->getClientRealIp();
    }
}


