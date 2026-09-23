<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Agent\AgentContext;
use App\Agent\AnalysisMode;
use App\Agent\AnalysisRecordManager;
use App\Agent\AnalysisResult;
use App\Agent\PromptManager;
use App\Agent\ToolManager;
use App\Config;

beforeEach(function () {
    $logFile = CORE_PATH . '/runtime/logs/ai_analyses.log';
    if (file_exists($logFile)) {
        @unlink($logFile);
    }
});

afterEach(function () {
    $logFile = CORE_PATH . '/runtime/logs/ai_analyses.log';
    if (file_exists($logFile)) {
        @unlink($logFile);
    }
});

test('AnalysisRecordManager records and lists analyses with filtering', function () {
    $emitter = new class implements \App\Sse\AnalysisEmitter {
        public function begin(): void {}
        public function emit(string $event, string $data): void {}
        public function finish(string $event, string $data): void {}
    };
    $ctx = new AgentContext('sample log content', 'sample_cache_key_1', 'log_123', $emitter, AnalysisMode::DEEP, 'v1');
    $result = new AnalysisResult(
        cacheKey: 'sample_cache_key_1',
        success: true,
        rounds: 2,
        toolCallChain: [['name' => 'rag_search']],
        metrics: ['durationMs' => 2500],
        validation: [
            'structuredOutput' => [
                'rootCause' => 'NullPointerException in Mod A',
            ],
        ],
        score: [
            'overall' => 88.5,
            'toolEfficiency' => 90.0,
            'evidenceSufficiency' => 85.0,
            'conclusionClarity' => 90.0,
        ],
        fullAnswer: 'Diagnosed crash: NullPointerException in Mod A.',
        trace: ['model' => 'test-model', 'startedAt' => '2026-09-22T00:00:00Z']
    );

    AnalysisRecordManager::record($result, $ctx);

    $detail = AnalysisRecordManager::get('sample_cache_key_1');
    expect($detail)->not->toBeNull();
    expect($detail['id'])->toBe('sample_cache_key_1');
    expect($detail['mode'])->toBe('deep');
    expect($detail['score']['overall'])->toBe(88.5);

    $trace = AnalysisRecordManager::getTrace('sample_cache_key_1');
    expect($trace)->toBeArray();
    expect($trace['model'])->toBe('test-model');

    $list = AnalysisRecordManager::list(['mode' => 'deep'], 1, 10);
    expect($list['total'])->toBeGreaterThanOrEqual(1);

    $scoreboard = AnalysisRecordManager::getScoreboard(7);
    expect($scoreboard)->toHaveKeys(['totalAnalyses', 'averageScore', 'modeDistribution']);

    $slow = AnalysisRecordManager::getSlowAnalyses(10, 1000.0);
    expect($slow)->toBeArray();

    $deleted = AnalysisRecordManager::delete('sample_cache_key_1');
    expect($deleted)->toBeTrue();
});

test('scoreboard aggregates every record in the window, not just the first page', function () {
    // 回归：getScoreboard() 曾以 pageSize=1000 调 list()，而 list() 默认把页大小夹到
    // 100，导致 totalAnalyses 恒为 100、均值只统计最新 100 条，days 参数形同失效。
    $emitter = new class implements \App\Sse\AnalysisEmitter {
        public function begin(): void {}
        public function emit(string $event, string $data): void {}
        public function finish(string $event, string $data): void {}
    };

    $seeded = 105;
    foreach (range(1, $seeded) as $i) {
        $key = 'regression_scoreboard_' . $i;
        $ctx = new AgentContext('log content', $key, null, $emitter, AnalysisMode::DEEP, 'v1');
        AnalysisRecordManager::record(new AnalysisResult(
            cacheKey: $key,
            success: true,
            rounds: 1,
            toolCallChain: [],
            metrics: ['durationMs' => 1000],
            validation: [],
            // 全部低分且互不相同：均值与低分计数都能区分「聚合全量」与「只看 100 条」
            score: ['overall' => 10, 'toolEfficiency' => 10, 'evidenceSufficiency' => 10, 'conclusionClarity' => 10],
            fullAnswer: 'low',
            trace: ['model' => 'test-model', 'startedAt' => date('c')]
        ), $ctx);
    }

    $scoreboard = AnalysisRecordManager::getScoreboard(7);
    expect($scoreboard['totalAnalyses'])->toBeGreaterThanOrEqual($seeded);
    expect($scoreboard['lowScoreCount'])->toBeGreaterThanOrEqual($seeded);

    foreach (range(1, $seeded) as $i) {
        AnalysisRecordManager::delete('regression_scoreboard_' . $i);
    }
});

test('PromptManager manages prompt versions and lifecycle', function () {
    $prompts = PromptManager::listPrompts();
    expect($prompts)->toBeArray();
    expect(count($prompts))->toBeGreaterThanOrEqual(1);
    expect($prompts[0]['version'])->toBe('v1');

    $v1 = PromptManager::getPrompt('v1');
    expect($v1['isSystem'])->toBeTrue();
    expect($v1['content'])->toContain('Minecraft');

    // Create a new version
    PromptManager::savePrompt('v-test', 'Custom system prompt for testing.', true);
    $custom = PromptManager::getPrompt('v-test');
    expect($custom['version'])->toBe('v-test');
    expect($custom['content'])->toBe('Custom system prompt for testing.');

    // Activate
    PromptManager::activatePrompt('v-test');
    $activePrompts = PromptManager::listPrompts();
    $found = false;
    foreach ($activePrompts as $p) {
        if ($p['version'] === 'v-test' && $p['active'] === true) {
            $found = true;
            break;
        }
    }
    expect($found)->toBeTrue();

    // Switch back and delete
    PromptManager::activatePrompt('v1');
    PromptManager::deletePrompt('v-test');

    $afterDelete = PromptManager::listPrompts();
    $exists = false;
    foreach ($afterDelete as $p) {
        if ($p['version'] === 'v-test') {
            $exists = true;
            break;
        }
    }
    expect($exists)->toBeFalse();
});

test('ToolManager manages tool availability and configuration', function () {
    $tools = ToolManager::listTools();
    expect($tools)->toBeArray();
    expect(count($tools))->toBe(9);

    expect(ToolManager::isToolEnabled('rag_search'))->toBeTrue();

    // Disable a tool
    ToolManager::setToolEnabled('web_search_exa', false);
    expect(ToolManager::isToolEnabled('web_search_exa'))->toBeFalse();

    // Re-enable
    ToolManager::setToolEnabled('web_search_exa', true);
    expect(ToolManager::isToolEnabled('web_search_exa'))->toBeTrue();

    // Update config
    ToolManager::updateToolConfig('rag_search', ['maxRetries' => 4]);
    $updatedTools = ToolManager::listTools();
    foreach ($updatedTools as $t) {
        if ($t['name'] === 'rag_search') {
            expect($t['maxRetries'])->toBe(4);
        }
    }
});

test('AnalysisMode provides modes and limits', function () {
    $all = AnalysisMode::allModes();
    expect($all)->toBeArray();
    $modes = array_column($all, 'mode');
    expect($modes)->toContain('deep', 'launcher', 'quick');

    $limits = AnalysisMode::limits(AnalysisMode::LAUNCHER);
    expect($limits['maxRounds'])->toBe(20);
});
