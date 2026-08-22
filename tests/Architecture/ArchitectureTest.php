<?php

uses()->group('architecture');

function architectureFiles(string $subdir): array
{
    $files = glob(__DIR__ . '/../../app/' . $subdir . '/*.php');
    if ($files === false || count($files) === 0) {
        throw new RuntimeException('No source files found for architecture test: ' . $subdir);
    }
    return $files;
}

test('controllers do not use $_SERVER directly', function () {
    foreach (architectureFiles('Controller') as $file) {
        $content = file_get_contents($file);
        if (basename($file) === 'AbstractController.php') continue;

        expect($content)->not->toContain('$_SERVER[', "File $file uses \$_SERVER directly. Use RequestInterface instead.");
    }
});

test('controllers do not use $_GET/$_POST directly', function () {
    foreach (architectureFiles('Controller') as $file) {
        $content = file_get_contents($file);
        if (basename($file) === 'AbstractController.php') continue;

        expect($content)->not->toContain('$_GET[', "File $file uses \$_GET directly. Use ContentParser instead.");
        expect($content)->not->toContain('$_POST[', "File $file uses \$_POST directly. Use ContentParser instead.");
    }
});

test('no raw SQL in controllers', function () {
    foreach (architectureFiles('Controller') as $file) {
        $content = file_get_contents($file);
        if (basename($file) === 'AbstractController.php') continue;

        expect($content)->not->toMatch('/(SELECT|INSERT|UPDATE|DELETE).*FROM/i', "File $file appears to have raw SQL. Use Storage classes.");
    }
});

test('all controllers extend base AbstractController', function () {
    foreach (architectureFiles('Controller') as $file) {
        $className = basename($file, '.php');
        if ($className === 'AbstractController') continue;

        $class = '\App\Controller\\' . $className;
        if (class_exists($class)) {
            expect(is_subclass_of($class, '\App\Controller\AbstractController'))->toBeTrue("$className must extend \\App\\Controller\\AbstractController");
        }
    }
});

test('filters are in Filter namespace', function () {
    $filterFiles = architectureFiles('Filter');

    foreach ($filterFiles as $file) {
        $className = basename($file, '.php');
        if (in_array($className, ['Filter', 'Pattern', 'PatternWithReplacement'])) {
            continue;
        }
        
        $class = '\App\Filter\\' . $className;
        if (class_exists($class)) {
            expect(is_subclass_of($class, '\App\Filter\Filter'))->toBeTrue("$className must extend \\App\\Filter\\Filter");
        }
    }
});

test('storage classes implement StorageInterface', function () {
    foreach (architectureFiles('Storage') as $file) {
        $className = basename($file, '.php');
        if ($className === 'StorageInterface') continue;
        
        $class = '\App\Storage\\' . $className;
        if (class_exists($class)) {
            expect(is_subclass_of($class, '\App\Storage\StorageInterface'))->toBeTrue("$className must implement \\App\\Storage\\StorageInterface");
        }
    }
});

test('cache classes implement CacheInterface', function () {
    foreach (architectureFiles('Cache') as $file) {
        $className = basename($file, '.php');
        if ($className === 'CacheInterface') continue;
        
        $class = '\App\Cache\\' . $className;
        if (class_exists($class)) {
            expect(is_subclass_of($class, '\App\Cache\CacheInterface'))->toBeTrue("$className must implement \\App\\Cache\\CacheInterface");
        }
    }
});