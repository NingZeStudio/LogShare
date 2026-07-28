<?php

uses()->group('architecture');

test('handlers do not use $_SERVER directly', function () {
    $handlerFiles = glob(__DIR__ . '/../../../src/Handler/*.php');
    
    foreach ($handlerFiles as $file) {
        $content = file_get_contents($file);
        // Allow in base Handler class for method validation
        if (basename($file) === 'Handler.php') continue;
        
        expect($content)->not->toContain('$_SERVER[')
            ->withMessage("File $file uses \$_SERVER directly. Use RequestValidator instead.");
    }
});

test('handlers do not use $_GET/$_POST directly', function () {
    $handlerFiles = glob(__DIR__ . '/../../../src/Handler/*.php');
    
    foreach ($handlerFiles as $file) {
        $content = file_get_contents($file);
        if (basename($file) === 'Handler.php') continue;
        
        expect($content)->not->toContain('$_GET[')
            ->withMessage("File $file uses \$_GET directly. Use ContentParser instead.");
        expect($content)->not->toContain('$_POST[')
            ->withMessage("File $file uses \$_POST directly. Use ContentParser instead.");
    }
});

test('no raw SQL in handlers', function () {
    $handlerFiles = glob(__DIR__ . '/../../../src/Handler/*.php');
    
    foreach ($handlerFiles as $file) {
        $content = file_get_contents($file);
        if (basename($file) === 'Handler.php') continue;
        
        expect($content)->not->toMatch('/(SELECT|INSERT|UPDATE|DELETE).*FROM/i')
            ->withMessage("File $file appears to have raw SQL. Use Storage classes.");
    }
});

test('all handlers extend base Handler', function () {
    $handlerFiles = glob(__DIR__ . '/../../../src/Handler/*.php');
    
    foreach ($handlerFiles as $file) {
        $className = basename($file, '.php');
        if ($className === 'Handler') continue;
        
        $class = '\Handler\\' . $className;
        if (class_exists($class)) {
            expect(is_subclass_of($class, '\Handler'))->toBeTrue()
                ->withMessage("$className must extend \\Handler");
        }
    }
});

test('filters are in Filter namespace', function () {
    $filterFiles = glob(__DIR__ . '/../../../src/Filter/*.php');
    $filterFiles = array_merge($filterFiles, glob(__DIR__ . '/../../../src/Filter/Pre/*.php'));
    
    foreach ($filterFiles as $file) {
        $className = basename($file, '.php');
        if (in_array($className, ['Filter', 'FilterType', 'Pattern', 'PatternWithReplacement', 'PreFilterInterface'])) {
            continue;
        }
        
        $class = '\Filter\\' . $className;
        if (class_exists($class)) {
            expect(is_subclass_of($class, '\Filter\Filter'))->toBeTrue()
                ->withMessage("$className must extend \\Filter\\Filter");
        }
    }
});

test('storage classes implement StorageInterface', function () {
    $storageFiles = glob(__DIR__ . '/../../../src/Storage/*.php');
    
    foreach ($storageFiles as $file) {
        $className = basename($file, '.php');
        if ($className === 'StorageInterface') continue;
        
        $class = '\Storage\\' . $className;
        if (class_exists($class)) {
            expect(is_subclass_of($class, '\Storage\StorageInterface'))->toBeTrue()
                ->withMessage("$className must implement \\Storage\\StorageInterface");
        }
    }
});

test('cache classes implement CacheInterface', function () {
    $cacheFiles = glob(__DIR__ . '/../../../src/Cache/*.php');
    
    foreach ($cacheFiles as $file) {
        $className = basename($file, '.php');
        if (in_array($className, ['CacheInterface', 'CacheEntry'])) continue;
        
        $class = '\Cache\\' . $className;
        if (class_exists($class)) {
            expect(is_subclass_of($class, '\Cache\CacheInterface'))->toBeTrue()
                ->withMessage("$className must implement \\Cache\\CacheInterface");
        }
    }
});