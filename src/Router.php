<?php

class Router
{
    private static array $match = [];
    private static ?array $compiledRoutes = null;

    public static function param(string $name): ?string
    {
        return self::$match[$name] ?? null;
    }

    public static function dispatch(): never
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/') ?: '/';

        $compiled = self::getCompiledRoutes();
        $routes = $compiled['routes'];
        $disabled = $compiled['disabled'];

        foreach ($routes as $route) {
            if ($route[0] !== $method) {
                continue;
            }

            $routePath = rtrim($route[1], '/') ?: '/';
            if (in_array("{$route[0]} {$route[1]}", $disabled, true)) {
                continue;
            }

            $params = self::matchCompiled($route, $routePath, $uri);
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

    /**
     * Build the route table once and cache the compiled regex per route.
     *
     * @return array{0?: mixed, routes: array, disabled: array}
     */
    private static function getCompiledRoutes(): array
    {
        if (self::$compiledRoutes !== null) {
            return self::$compiledRoutes;
        }

        $routeConfig = self::getRoutes();
        $compiled = [];

        foreach ($routeConfig['routes'] as $route) {
            $routePath = rtrim($route[1], '/') ?: '/';
            $compiled[] = [
                $route[0],
                $routePath,
                $route[2] ?? null,
                $route[3] ?? null,
                self::compilePath($routePath),
            ];
        }

        return self::$compiledRoutes = [
            'routes' => $compiled,
            'disabled' => $routeConfig['disabled'] ?? [],
        ];
    }

    /**
     * Match a compiled route against a URI.
     *
     * @param array $route
     * @param string $routePath
     * @param string $uri
     * @return array|null
     */
    private static function matchCompiled(array $route, string $routePath, string $uri): ?array
    {
        if ($route[4] === null) {
            return $routePath === $uri ? [] : null;
        }

        if (!preg_match($route[4], $uri, $matches)) {
            return null;
        }

        return array_filter($matches, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY) ?: [];
    }

    /**
     * Compile a route path pattern into a regular expression.
     *
     * Supports `{name}` and `{name:regex}` placeholders. Returns null when the
     * pattern contains no placeholders (exact match).
     *
     * @param string $pattern
     * @return string|null
     */
    private static function compilePath(string $pattern): ?string
    {
        if (!str_contains($pattern, '{')) {
            return null;
        }

        $regex = preg_replace_callback(
            '/\{([a-zA-Z_]+)(?::([^}]+))?\}/',
            function ($matches) {
                $name = $matches[1];
                $subPattern = $matches[2] ?? '[^/]+';
                return '(?P<' . $name . '>' . $subPattern . ')';
            },
            $pattern
        );

        return '#^' . $regex . '$#';
    }

    /**
     * Match a path pattern against a URI. Public for tests.
     *
     * @param string $pattern
     * @param string $uri
     * @return array|null
     */
    public static function matchPath(string $pattern, string $uri): ?array
    {
        $regex = self::compilePath($pattern);
        if ($regex === null) {
            return $pattern === $uri ? [] : null;
        }

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
            // Atomic INCR + one-time EXPIRE avoids the read-modify-write race
            $current = \Cache\RedisCache::Incr($key);
            if ($current === 1) {
                \Cache\RedisCache::Expire($key, $window);
            }
            if ($current > $limit) {
                ApiResponse::error('Rate limit exceeded. Please try again later.', 429);
            }
        } catch (\Throwable $e) {
            // Fail-open: if Redis is unavailable, skip rate limiting rather than
            // rejecting the request (or worse, leaking a fatal error).
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
                ['GET',     '/1/raw/{id}/{filename:.+}', \Handler\RawHandler::class, [36000, 60]          ],
                ['GET',     '/1/log/{id}',         \Handler\LogMetaHandler::class,   [36000, 60]          ],
                ['GET',     '/1/insights/{id}',    \Handler\InsightsHandler::class,  [36000, 60]          ],
                ['GET',     '/1/ai/{id}',          \Handler\AIHandler::class,        [36000, 60]          ],
                ['POST',    '/1/ai/analyse',       \Handler\AIAnalyseHandler::class, [36000, 60]          ],

                ['POST',    '/v1/log',             \Handler\LogHandler::class,       [36000, 60]          ],
                ['DELETE',  '/v1/log/{id}',        \Handler\LogHandler::class,       [36000, 60]          ],
                ['POST',    '/v1/analyse',         \Handler\AnalyseHandler::class,   [36000, 60]          ],
                ['GET',     '/v1/errors/rate',     \Handler\RateErrorHandler::class, [36000, 60]          ],
                ['GET',     '/v1/limits',          \Handler\LimitsHandler::class,    [36000, 60]          ],
                ['GET',     '/v1/filters',         \Handler\FiltersHandler::class,   [36000, 60]          ],
                ['GET',     '/v1/raw/{id}',        \Handler\RawHandler::class,       [36000, 60]          ],
                ['GET',     '/v1/raw/{id}/{filename:.+}', \Handler\RawHandler::class, [36000, 60]          ],
                ['GET',     '/v1/log/{id}',        \Handler\LogMetaHandler::class,   [36000, 60]          ],
                ['GET',     '/v1/insights/{id}',   \Handler\InsightsHandler::class,  [36000, 60]          ],
                ['GET',     '/v1/ai/{id}',         \Handler\AIHandler::class,        [36000, 60]          ],
                ['POST',    '/v1/ai/analyse',      \Handler\AIAnalyseHandler::class, [36000, 60]          ],
            ],

            'disabled' => [
                // 'GET /1/errors/rate',
            ],
        ];
    }
}
