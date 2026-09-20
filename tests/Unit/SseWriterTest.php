<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exception\ClientDisconnectedException;
use App\Sse\SseWriter;
use Hyperf\Engine\Contract\Http\Writable;
use Hyperf\Engine\Http\EventStream;
use Mockery;
use ReflectionClass;

afterEach(function () {
    SseWriter::end();
});

test('SseWriter writes to stdout in CLI fallback mode', function () {
    SseWriter::begin(null);

    ob_start();
    SseWriter::write("data: hello\n\n");
    $output = ob_get_clean();

    expect($output)->toBe("data: hello\n\n");
});

test('SseWriter writes to connection and detects client disconnection', function () {
    $mockConn = Mockery::mock(Writable::class);
    $mockStream = Mockery::mock(EventStream::class);
    $mockStream->shouldReceive('end')->byDefault();

    $ref = new ReflectionClass(SseWriter::class);
    $storeMethod = $ref->getMethod('store');
    $storeMethod->invoke(null, $mockStream);
    $storeConnMethod = $ref->getMethod('storeConnection');
    $storeConnMethod->invoke(null, $mockConn);

    // 1. Write succeeds
    $mockConn->shouldReceive('write')->with("data: test\n\n")->once()->andReturn(12);
    SseWriter::write("data: test\n\n");

    // 2. Client disconnected (write returns false)
    $mockConn->shouldReceive('write')->with("data: dc\n\n")->once()->andReturn(false);
    expect(fn() => SseWriter::write("data: dc\n\n"))->toThrow(ClientDisconnectedException::class);

    // 3. End cleans up
    SseWriter::end();
});
