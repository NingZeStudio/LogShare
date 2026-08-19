<?php

test('route table registers all v1 endpoints', function () {
    $ref = new ReflectionClass(\App\Router::class);
    $method = $ref->getMethod('getRoutes');

    $routeConfig = $method->invoke(null);
    $routes = $routeConfig['routes'];

    $expected = [
        ['POST', '/v1/log'],
        ['DELETE', '/v1/log/{id}'],
        ['GET', '/v1/log/{id}'],
        ['POST', '/v1/analyse'],
        ['GET', '/v1/errors/rate'],
        ['GET', '/v1/limits'],
        ['GET', '/v1/filters'],
        ['GET', '/v1/raw/{id}'],
        ['GET', '/v1/raw/{id}/{filename:.+}'],
        ['GET', '/v1/insights/{id}'],
        ['GET', '/v1/ai/{id}'],
        ['POST', '/v1/ai/analyse'],
    ];

    foreach ($expected as [$methodName, $path]) {
        $found = false;
        foreach ($routes as $route) {
            if ($route[0] === $methodName && $route[1] === $path) {
                $found = true;
                break;
            }
        }
        expect($found)->toBeTrue("Missing route: {$methodName} {$path}");
    }
});

test('route table registers all legacy v1 endpoints', function () {
    $ref = new ReflectionClass(\App\Router::class);
    $method = $ref->getMethod('getRoutes');

    $routeConfig = $method->invoke(null);
    $routes = $routeConfig['routes'];

    $expected = [
        ['POST', '/1/log'],
        ['DELETE', '/1/log/{id}'],
        ['GET', '/1/raw/{id}'],
        ['GET', '/1/raw/{id}/{filename:.+}'],
        ['GET', '/1/ai/{id}'],
        ['POST', '/1/ai/analyse'],
    ];

    foreach ($expected as [$methodName, $path]) {
        $found = false;
        foreach ($routes as $route) {
            if ($route[0] === $methodName && $route[1] === $path) {
                $found = true;
                break;
            }
        }
        expect($found)->toBeTrue("Missing route: {$methodName} {$path}");
    }
});

test('route table handlers resolve to existing classes', function () {
    $ref = new ReflectionClass(\App\Router::class);
    $method = $ref->getMethod('getRoutes');

    $routeConfig = $method->invoke(null);

    foreach ($routeConfig['routes'] as $route) {
        if ($route[2] !== null) {
            expect(class_exists($route[2]))->toBeTrue("Handler class not found: {$route[2]}");
        }
    }
});

test('router disabled list uses valid route identifiers', function () {
    $ref = new ReflectionClass(\App\Router::class);
    $method = $ref->getMethod('getRoutes');

    $routeConfig = $method->invoke(null);
    $disabled = $routeConfig['disabled'] ?? [];

    $existing = array_map(fn($route) => "{$route[0]} {$route[1]}", $routeConfig['routes']);

    expect($disabled)->toBeArray();
    foreach ($disabled as $entry) {
        expect(in_array($entry, $existing, true))->toBeTrue("Disabled route not in table: {$entry}");
    }
});