<?php

class Router
{
    private static array $match = [];

    public static function param(string $name): ?string
    {
        return self::$match[$name] ?? null;
    }

    public static function dispatch(): never
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/') ?: '/';

        $routeConfig = self::getRoutes();
        $routes = $routeConfig['routes'];
        $disabled = $routeConfig['disabled'] ?? [];

        foreach ($routes as $route) {
            if ($route[0] !== $method) {
                continue;
            }

            $routePath = rtrim($route[1], '/') ?: '/';
            if (in_array("{$route[0]} {$route[1]}", $disabled, true)) {
                continue;
            }

            $params = self::matchPath($routePath, $uri);
            if ($params === null) {
                continue;
            }

            self::$match = $params;

            if (isset($route[3])) {
                self::checkRateLimit($route[0], $route[1], $route[3]);
            }

            if ($route[2] === null) {
                self::handleHome($routes);
            } else {
                $handlerClass = $route[2];
                if (class_exists($handlerClass)) {
                    $handler = new $handlerClass();
                    $handler->handle();
                } else {
                    ApiResponse::error('Handler not found: ' . $handlerClass, 500);
                }
            }
            exit;
        }

        ApiResponse::error('Endpoint not found', 404);
    }

    private static function matchPath(string $pattern, string $uri): ?array
    {
        $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $uri, $matches)) {
            return null;
        }

        return array_filter($matches, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY) ?: [];
    }

    private static function checkRateLimit(string $method, string $path, array $rateLimit): void
    {
        [$limit, $window] = $rateLimit;
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = "rl:{$method}:{$path}:{$ip}";

        try {
            $current = (int)\Cache\RedisCache::Get($key);
            if ($current === 0) {
                \Cache\RedisCache::Set($key, '1', $window);
            } elseif ($current >= $limit) {
                ApiResponse::error('Rate limit exceeded. Please try again later.', 429);
            } else {
                \Cache\RedisCache::Set($key, (string)($current + 1), $window);
            }
        } catch (\Exception $e) {
            error_log("[Router] Rate limit check failed: " . $e->getMessage());
        }
    }

    private static function handleHome(array $routes): never
    {
        $endpoints = [];
        foreach ($routes as $route) {
            if ($route[1] === '/') {
                continue;
            }
            $endpoints[] = $route[0] . ' ' . $route[1];
        }

        ApiResponse::success([
            'endpoints' => $endpoints,
        ], 'LogShare API');
    }

    private static function getRoutes(): array
    {
        return [
            'routes' => [
                ['GET',     '/',                   null                                                ],
                ['POST',    '/1/log',              \Handler\LogHandler::class,       [36000, 60]          ],
                ['DELETE',  '/1/log/{id}',         \Handler\LogHandler::class,       [36000, 60]          ],
                ['POST',    '/1/analyse',          \Handler\AnalyseHandler::class,   [36000, 60]          ],
                ['GET',     '/1/errors/rate',      \Handler\RateErrorHandler::class, [36000, 60]          ],
                ['GET',     '/1/limits',           \Handler\LimitsHandler::class,    [36000, 60]          ],
                ['GET',     '/1/filters',          \Handler\FiltersHandler::class,   [36000, 60]          ],
                ['GET',     '/1/raw/{id}',         \Handler\RawHandler::class,       [36000, 60]          ],
                ['GET',     '/1/insights/{id}',    \Handler\InsightsHandler::class,  [36000, 60]          ],
                ['GET',     '/1/ai/{id}',          \Handler\AIHandler::class,        [36000, 60]          ],
                ['POST',    '/1/ai/analyse',       \Handler\AIAnalyseHandler::class, [36000, 60]          ],
            ],

            'disabled' => [
                // 'GET /1/errors/rate',
            ],
        ];
    }
}
