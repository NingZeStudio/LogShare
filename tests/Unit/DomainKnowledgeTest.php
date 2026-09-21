<?php

use App\Agent\LogAgent;
use App\System\DomainKnowledgeManager;

beforeEach(function () {
    $this->testFile = CORE_PATH . '/runtime/domain_knowledge.json';
    $this->backup = file_exists($this->testFile) ? file_get_contents($this->testFile) : null;
    if (file_exists($this->testFile)) {
        @unlink($this->testFile);
    }
});

afterEach(function () {
    if ($this->backup !== null) {
        file_put_contents($this->testFile, $this->backup);
    } elseif (file_exists($this->testFile)) {
        @unlink($this->testFile);
    }
});

test('DomainKnowledgeManager can add, get, update, and delete items', function () {
    // 初始应为空
    expect(DomainKnowledgeManager::getAll())->toBe([]);
    expect(DomainKnowledgeManager::formatForPrompt())->toBe('');

    // 新增条目
    $item1 = DomainKnowledgeManager::add('PojavLauncher 在 Android 14+ 上若出现 SIGSEGV，优先切换至 gl4es 渲染器或更新运行库。', true);
    expect($item1)->toHaveKeys(['id', 'content', 'enabled', 'created_at', 'updated_at']);
    expect($item1['content'])->toContain('PojavLauncher');
    expect($item1['enabled'])->toBeTrue();

    // 查寻条目
    $found = DomainKnowledgeManager::get($item1['id']);
    expect($found)->not->toBeNull();
    expect($found['content'])->toBe($item1['content']);

    // 新增第二条（禁用状态）
    $item2 = DomainKnowledgeManager::add('Purpur 核心 1.20.4 已知与某一旧版登录插件存在兼容冲突，需升级至最新构建。', false);
    $all = DomainKnowledgeManager::getAll();
    expect($all)->toHaveCount(2);

    // 检查提示词生成：只包含 enabled 为 true 的条目，且段落为“已知领域知识”
    $promptText = DomainKnowledgeManager::formatForPrompt();
    expect($promptText)->toContain('已知领域知识：');
    expect($promptText)->toContain('PojavLauncher');
    expect($promptText)->not->toContain('Purpur');

    // 更新条目内容与启用状态
    $updated = DomainKnowledgeManager::update($item2['id'], '更新后的 Purpur 兼容知识。', true);
    expect($updated)->not->toBeNull();
    expect($updated['content'])->toBe('更新后的 Purpur 兼容知识。');
    expect($updated['enabled'])->toBeTrue();

    // 更新后提示词应包含两条
    $promptTextAfter = DomainKnowledgeManager::formatForPrompt();
    expect($promptTextAfter)->toContain('更新后的 Purpur 兼容知识。');

    // 删除条目
    $deleted = DomainKnowledgeManager::delete($item1['id']);
    expect($deleted)->toBeTrue();
    expect(DomainKnowledgeManager::getAll())->toHaveCount(1);
});

test('DomainKnowledgeManager rejects content over 200 characters or empty content', function () {
    // 空内容应抛出异常
    expect(fn() => DomainKnowledgeManager::add('   '))
        ->toThrow(\InvalidArgumentException::class, '领域知识内容不能为空');

    // 刚好 200 字应允许
    $text200 = str_repeat('知', 200);
    $item = DomainKnowledgeManager::add($text200);
    expect(mb_strlen($item['content'], 'UTF-8'))->toBe(200);

    // 201 字应抛出异常
    $text201 = str_repeat('知', 201);
    expect(fn() => DomainKnowledgeManager::add($text201))
        ->toThrow(\InvalidArgumentException::class, '领域知识单条长度不能超过 200 字');
});

test('LogAgent buildMessages includes Known Domain Knowledge and refined CoT instructions', function () {
    DomainKnowledgeManager::add('测试用已知领域知识：Paper 1.20 必须搭配 Java 17+。', true);

    $ref = new ReflectionClass(LogAgent::class);
    $method = $ref->getMethod('buildMessages');
    /** @var array<int, array{role: string, content: string}> $messages */
    $messages = $method->invokeArgs(null, [
        "Caused by: java.lang.NullPointerException\n  at com.example.TestMod.init(TestMod.java:42)",
        's123456',
        [],
        ''
    ]);

    $systemPrompt = $messages[0]['content'];

    // 必须包含管理员注入的已知领域知识
    expect($systemPrompt)->toContain('已知领域知识：');
    expect($systemPrompt)->toContain('Paper 1.20 必须搭配 Java 17+');

    // 必须包含优化的 CoT 要求：至少进行一次日志上下文线索查找
    expect($systemPrompt)->toContain('必须至少进行一次日志上下文线索查找');
    expect($systemPrompt)->toContain('严禁仅凭截取的单点报错片段浮于表面仓促下结论');
});
