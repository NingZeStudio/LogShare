<?php

declare(strict_types=1);

use App\Client\RedisClient;
use App\Rag\RagSearch;
use App\Rag\SemanticCache;
use App\Rag\SemanticClient;
use Tests\Mocks\RedisMock;

/** 确定性向量源：记录每次 embed 的批量大小，可脚本化失败与维度 */
class FakeSemantic extends SemanticClient
{
    /** @var int[] 每次调用的 texts 数量 */
    public array $sizes = [];
    /** @var array<int, array{dims?: int, fail?: bool}> 按调用序号覆写行为 */
    public array $script = [];

    public function __construct()
    {
        parent::__construct([]);
    }

    public function embed(array $texts): array
    {
        $this->sizes[] = count($texts);
        $i = count($this->sizes) - 1;
        $spec = $this->script[$i] ?? [];
        if (!empty($spec['fail'])) {
            throw new \RuntimeException('simulated provider failure');
        }
        $dims = $spec['dims'] ?? 3;
        return array_map(fn() => array_fill(0, $dims, 0.1), array_values($texts));
    }
}

function scSetConfig(array $patch): void
{
    $dataProp = (new ReflectionClass(\App\Config::class))->getProperty('data');
    if (!isset($GLOBALS['scOrigConfig'])) {
        $GLOBALS['scOrigConfig'] = [$dataProp, $dataProp->getValue()];
    }
    $data = $dataProp->getValue() ?? [];
    foreach ($patch as $path => $value) {
        $ref = &$data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($ref[$key] ?? null)) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
        $ref = $value;
        unset($ref);
    }
    $dataProp->setValue(null, $data);
}

function scCallEmbedChunks(RagSearch $rag, array $rowids, array $bodies, SemanticClient $fake): int
{
    $m = new ReflectionMethod(RagSearch::class, 'embedChunks');
    return $m->invoke($rag, $rag->getPdo(), $rowids, $bodies, $fake);
}

function scDbPath(): string
{
    $path = CORE_PATH . '/tmp/rag_sc_' . uniqid() . '.db';
    $GLOBALS['scDb'] = $path;
    return $path;
}

function scInsertDocs(RagSearch $rag, int $n): array
{
    $rowids = [];
    $bodies = [];
    $stmt = $rag->getPdo()->prepare('INSERT INTO docs(title, body, source) VALUES (?, ?, ?)');
    for ($i = 0; $i < $n; $i++) {
        $stmt->execute(['T' . $i, 'body ' . $i, 'tools/f' . $i . '.md']);
        $rowids[] = (int) $rag->getPdo()->lastInsertId();
        $bodies[] = 'title' . $i . "\n" . 'body ' . $i;
    }
    return [$rowids, $bodies];
}

beforeEach(function () {
    RedisMock::reset();
    RedisClient::setTestConnection(new RedisMock());
    SemanticCache::clearMemory();
});

afterEach(function () {
    RedisClient::setTestConnection(null);
    if (!empty($GLOBALS['scOrigConfig'])) {
        [$dataProp, $orig] = $GLOBALS['scOrigConfig'];
        $dataProp->setValue(null, $orig);
        unset($GLOBALS['scOrigConfig']);
    }
    if (!empty($GLOBALS['scDb']) && file_exists($GLOBALS['scDb'])) {
        unlink($GLOBALS['scDb']);
    }
    unset($GLOBALS['scDb']);
});

/* ─── SemanticCache：开关 ───────────────────────────────── */

test('semanticCache is enabled by default and follows config', function () {
    scSetConfig(['ai.rag' => []]);
    expect(SemanticCache::enabled())->toBeTrue('缺省即开启（只省 API，不改结果）');

    scSetConfig(['ai.rag.semanticCache' => false]);
    expect(SemanticCache::enabled())->toBeFalse();
});

/* ─── SemanticCache：读写与双层结构 ─────────────────────── */

test('set/get round-trips through redis layer and repopulates memory', function () {
    $cache = new SemanticCache('siliconflow/BAAI/bge-m3');
    $cache->set('崩溃 日志', [0.1, 0.2, 0.3]);

    // 命中进程内层
    expect($cache->get('崩溃 日志'))->toBe([0.1, 0.2, 0.3]);

    // 清空内存层后走 Redis 层
    SemanticCache::clearMemory();
    expect($cache->get('崩溃 日志'))->toBe([0.1, 0.2, 0.3], 'Redis 层命中');

    // Redis 层命中后回填了内存层：断开 Redis 仍命中
    RedisMock::$failAll = true;
    expect($cache->get('崩溃 日志'))->toBe([0.1, 0.2, 0.3]);
});

test('cache keys isolate by model fingerprint and query', function () {
    $a = new SemanticCache('model-a/bge-m3');
    $b = new SemanticCache('model-b/bge-m3');
    $a->set('q', [1.0, 2.0]);

    expect($b->get('q'))->toBeNull('切换 embedding 模型后旧向量不可复用');
    expect($a->get('other'))->toBeNull();
});

test('corrupted redis payloads degrade to miss', function () {
    scSetConfig(['ai.rag' => []]);
    $cache = new SemanticCache('fp');
    $key = 'rag:embed:query:' . hash('sha256', 'fp|q');
    $redis = RedisClient::getRedis();

    $redis->set($key, 'not-json');
    expect($cache->get('q'))->toBeNull();

    $redis->set($key, json_encode(['x', 1.0]));
    expect($cache->get('q'))->toBeNull('非数值向量拒绝');

    $redis->set($key, json_encode([]));
    expect($cache->get('q'))->toBeNull('空向量拒绝');
});

test('disabled cache never reads or writes', function () {
    scSetConfig(['ai.rag.semanticCache' => false]);
    $cache = new SemanticCache('fp-off');
    $cache->set('q', [1.0]);
    expect($cache->get('q'))->toBeNull();
});

test('cache survives redis outage entirely in memory', function () {
    scSetConfig(['ai.rag' => []]);
    RedisMock::$failAll = true;
    $cache = new SemanticCache('fp');
    $cache->set('q', [0.5]);
    expect($cache->get('q'))->toBe([0.5]);
});

/* ─── embedChunks：batch 自适应 ─────────────────────────── */

test('successful batches grow +4 capped at 64', function () {
    $rag = new RagSearch(scDbPath());
    [$rowids, $bodies] = scInsertDocs($rag, 40);
    $fake = new FakeSemantic();

    $embedded = scCallEmbedChunks($rag, $rowids, $bodies, $fake);

    expect($embedded)->toBe(40);
    expect($fake->sizes)->toBe([16, 20, 4], '16 成功扩到 20，再 24，尾批 4');
    expect((int) $rag->getPdo()->query('SELECT count(*) FROM doc_embeddings')->fetchColumn())->toBe(40);
});

test('failed batch halves size and retries per chunk', function () {
    $rag = new RagSearch(scDbPath());
    [$rowids, $bodies] = scInsertDocs($rag, 20);
    $fake = new FakeSemantic();
    $fake->script = [0 => ['fail' => true]];

    $embedded = scCallEmbedChunks($rag, $rowids, $bodies, $fake);

    expect($embedded)->toBe(20);
    // 首批 16 失败 → 16 次单条重试 → 剩余 4 条（batchSize 收缩到 8，尾批取 min）
    expect($fake->sizes[0])->toBe(16);
    expect(array_slice($fake->sizes, 1, 16))->toBe(array_fill(0, 16, 1));
    expect(end($fake->sizes))->toBe(4);
});

test('persistent dims drift keeps only consistent-dimension vectors', function () {
    $rag = new RagSearch(scDbPath());
    [$rowids, $bodies] = scInsertDocs($rag, 20);
    $fake = new FakeSemantic();
    // 首批 3 维正常；第二批漂到 5 维（重嵌入与逐条都仍 5 维 → 全部拒收）
    $fake->script = [
        1 => ['dims' => 5],
        2 => ['dims' => 5],
        3 => ['dims' => 5],
        4 => ['dims' => 5],
        5 => ['dims' => 5],
        6 => ['dims' => 5],
    ];

    $embedded = scCallEmbedChunks($rag, $rowids, $bodies, $fake);

    expect($embedded)->toBe(16, '漂移批不落库，防止污染余弦扫描');
    expect((int) $rag->getPdo()->query('SELECT count(*) FROM doc_embeddings')->fetchColumn())->toBe(16);
});

test('transient dims drift recovers on re-embed', function () {
    $rag = new RagSearch(scDbPath());
    [$rowids, $bodies] = scInsertDocs($rag, 20);
    $fake = new FakeSemantic();
    $fake->script = [1 => ['dims' => 5]]; // 首批后一次漂移，重嵌入恢复 3 维

    $embedded = scCallEmbedChunks($rag, $rowids, $bodies, $fake);

    expect($embedded)->toBe(20);
});

/* ─── Ollama 本地 provider ──────────────────────────────── */

test('ollama provider accepts loopback http urls only', function () {
    $p = SemanticClient::ollama('local', 'http://localhost:11434', 'bge-m3');
    expect($p['local'])->toBeTrue();
    expect($p['apiKey'])->toBe('');
    expect(SemanticClient::ollama('l2', 'http://127.0.0.1:11434', 'm')['baseUrl'])->toBe('http://127.0.0.1:11434');

    expect(fn() => SemanticClient::ollama('bad', 'https://localhost:11434', 'm'))->toThrow(InvalidArgumentException::class);
    expect(fn() => SemanticClient::ollama('bad', 'http://evil.example.com', 'm'))->toThrow(InvalidArgumentException::class);
    expect(fn() => SemanticClient::ollama('bad', 'http://10.0.0.5:11434', 'm'))->toThrow(InvalidArgumentException::class, 'loopback'); // 非 loopback 私网也拒绝
});

test('remote provider url keeps rejecting private and loopback targets', function () {
    expect(fn() => SemanticClient::provider('p', 'http://localhost:11434', 'k', 'm'))->toThrow(InvalidArgumentException::class);
});

test('local-only client counts as configured and describes itself', function () {
    $client = new SemanticClient([SemanticClient::ollama('local', 'http://localhost:11434', 'bge-m3')]);
    expect($client->isConfigured())->toBeTrue('Ollama 免鉴权');
    expect($client->describe())->toBe('local/bge-m3');

    // 仅未配置（无 key、非本地）的 provider → 不算可用
    $empty = new SemanticClient([['name' => 'x', 'baseUrl' => 'https://a.example', 'apiKey' => '', 'embeddingModel' => 'm', 'local' => false]]);
    expect($empty->isConfigured())->toBeFalse();
});

test('semanticClientFromConfig builds ollama provider from type field', function () {
    scSetConfig([
        'ai.rag.enabled' => true,
        'ai.rag.providers' => [
            ['type' => 'ollama', 'baseUrl' => 'http://127.0.0.1:11434', 'embeddingModel' => 'bge-m3'],
        ],
    ]);
    $client = RagSearch::semanticClientFromConfig();
    expect($client)->not->toBeNull();
    expect($client->isConfigured())->toBeTrue();
    expect($client->describe())->toBe('http://127.0.0.1:11434/bge-m3');
});
