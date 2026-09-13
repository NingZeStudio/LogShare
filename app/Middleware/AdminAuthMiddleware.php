<?php

declare(strict_types=1);

namespace App\Middleware;

use App\ApiError;
use App\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Admin authentication middleware.
 *
 * Checks Bearer token or X-Admin-Token against configured admin.token.
 * When admin.enabled is false, requests are rejected with 404.
 */
class AdminAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Allow CORS preflight requests to pass through
        if ($request->getMethod() === 'OPTIONS') {
            return $handler->handle($request);
        }

        $adminConfig = Config::Get('admin');
        if (($adminConfig['enabled'] ?? false) !== true) {
            throw new ApiError(404, 'Admin interface is disabled');
        }

        $expectedToken = (string) ($adminConfig['token'] ?? '');
        if ($expectedToken === '') {
            throw new ApiError(500, 'Admin interface is not configured');
        }

        $authHeader = $request->getHeaderLine('Authorization');
        $token = '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = trim(substr($authHeader, 7));
        } elseif ($request->hasHeader('X-Admin-Token')) {
            $token = trim($request->getHeaderLine('X-Admin-Token'));
        }

        if ($token === '' || !hash_equals($expectedToken, $token)) {
            throw new ApiError(401, 'Invalid or missing admin token');
        }

        return $handler->handle($request);
    }
}
