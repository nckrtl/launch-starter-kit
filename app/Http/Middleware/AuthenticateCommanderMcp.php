<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateCommanderMcp
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('commander.mcp_token');
        $provided = $request->bearerToken();

        if (! is_string($configured) || $configured === '' || ! is_string($provided) || ! hash_equals($configured, $provided)) {
            return response('Unauthorized', 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }
}
