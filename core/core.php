<?php

define('CORE_PATH', realpath(dirname(__FILE__)));

require_once(CORE_PATH.'/../vendor/autoload.php');

// Main autoloader for core classes
spl_autoload_register(function ($class) {
    $class = str_replace("\\", "/", $class);
    $classPath = CORE_PATH.'/src/'.$class.'.php';

    if(file_exists($classPath)) {
        include $classPath;
    }
});

// Additional namespace loaders for new code structure
spl_autoload_register(function ($class) {
    // Support Data\* classes
    if (str_starts_with($class, 'Data\\')) {
        $classPath = CORE_PATH.'/src/'.str_replace("\\", "/", $class).'.php';
        if(file_exists($classPath)) {
            include $classPath;
        }
    }
    
    // Support Filter\* classes
    if (str_starts_with($class, 'Filter\\')) {
        $classPath = CORE_PATH.'/src/'.str_replace("\\", "/", $class).'.php';
        if(file_exists($classPath)) {
            include $classPath;
        }
    }
    
    // Support Client\* classes
    if (str_starts_with($class, 'Client\\')) {
        $classPath = CORE_PATH.'/src/'.str_replace("\\", "/", $class).'.php';
        if(file_exists($classPath)) {
            include $classPath;
        }
    }
    
    // Support Storage\* classes
    if (str_starts_with($class, 'Storage\\')) {
        $classPath = CORE_PATH.'/src/'.str_replace("\\", "/", $class).'.php';
        if(file_exists($classPath)) {
            include $classPath;
        }
    }
    
    // Support Printer\* classes
    if (str_starts_with($class, 'Printer\\')) {
        $classPath = CORE_PATH.'/src/'.str_replace("\\", "/", $class).'.php';
        if(file_exists($classPath)) {
            include $classPath;
        }
    }
    
    // Support Cache\* classes
    if (str_starts_with($class, 'Cache\\')) {
        $classPath = CORE_PATH.'/src/'.str_replace("\\", "/", $class).'.php';
        if(file_exists($classPath)) {
            include $classPath;
        }
    }
});
