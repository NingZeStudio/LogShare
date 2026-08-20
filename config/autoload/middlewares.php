<?php

declare(strict_types=1);

return [
    'http' => [
        App\Middleware\CorsMiddleware::class,
        App\Middleware\RateLimitMiddleware::class,
    ],
];
