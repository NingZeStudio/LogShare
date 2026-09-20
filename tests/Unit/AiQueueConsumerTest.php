<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Client\RedisClient;
use App\Client\RedisStreams;
use App\Config;
use App\Process\AiQueueConsumer;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

beforeEach(function () {
    $this->configRef = new ReflectionClass(Config::class);
    $this->dataProp = $this->configRef->getProperty('data');
    $this->origData = $this->dataProp->getValue();
});

afterEach(function () {
    $this->dataProp->setValue(null, $this->origData);
    RedisClient::setTestConnection(null);
});

function createConsumer(): AiQueueConsumer
{
    $ref = new ReflectionClass(AiQueueConsumer::class);
    return $ref->newInstanceWithoutConstructor();
}

test('AiQueueConsumer isEnable reflects ai.queue.enabled config', function () {
    $consumer = createConsumer();

    $cfg = $this->origData;
    $cfg['ai']['queue']['enabled'] = false;
    $this->dataProp->setValue(null, $cfg);
    expect($consumer->isEnable(null))->toBeFalse();

    $cfg['ai']['queue']['enabled'] = true;
    $this->dataProp->setValue(null, $cfg);
    expect($consumer->isEnable(null))->toBeTrue();
});

test('AiQueueConsumer cleanStaleConsumers deletes inactive zero-pending consumers', function () {
    $redisMock = Mockery::mock();
    $redisMock->shouldReceive('xinfo')
        ->with('CONSUMERS', \App\Ai\AnalysisQueue::QUEUE_KEY, \App\Ai\AnalysisQueue::GROUP)
        ->andReturn([
            ['name' => 'worker-0', 'pending' => 0],
            ['name' => 'worker-stale', 'pending' => 0],
            ['name' => 'worker-busy', 'pending' => 1],
        ]);
    $redisMock->shouldReceive('xgroup')
        ->with('DELCONSUMER', \App\Ai\AnalysisQueue::QUEUE_KEY, \App\Ai\AnalysisQueue::GROUP, 'worker-stale')
        ->once()
        ->andReturn(0);

    RedisClient::setTestConnection($redisMock);

    $consumer = createConsumer();
    $method = new ReflectionMethod(AiQueueConsumer::class, 'cleanStaleConsumers');
    $method->invoke($consumer, ['worker-0', 'reclaimer']);

    expect(true)->toBeTrue();
});

test('AiQueueConsumer getRssMemoryBytes returns positive bytes', function () {
    $bytes = AiQueueConsumer::getRssMemoryBytes();
    expect($bytes)->toBeGreaterThan(0);
});

test('AiQueueConsumer maybeRecycle sets draining flag when threshold is met', function () {
    $consumer = createConsumer();
    $ref = new ReflectionClass($consumer);

    $busyProp = $ref->getProperty('busyJobs');
    $busyProp->setValue(null, 1); // 1 busy job so it does not call exit()

    $processedProp = $ref->getProperty('processedJobs');
    $processedProp->setValue(null, 500); // Exceeds max processed threshold

    $drainingProp = $ref->getProperty('draining');
    expect($drainingProp->getValue($consumer))->toBeFalse();

    $recycleMethod = $ref->getMethod('maybeRecycle');
    $recycleMethod->invoke($consumer);

    expect($drainingProp->getValue($consumer))->toBeTrue();

    // Reset static properties
    $busyProp->setValue(null, 0);
    $processedProp->setValue(null, 0);
});
