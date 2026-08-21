<?php

declare(strict_types=1);

namespace Tests;

use Hyperf\Testing\TestCase;

abstract class HttpTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__));
        }
        parent::setUp();
    }
}
