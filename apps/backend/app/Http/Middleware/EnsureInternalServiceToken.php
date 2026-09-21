<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates /internal/v1/* machine-to-machine endpoints (CAM-003).
 *
 * Temporary shared-secret check (X-Internal-Token header vs
 * services.internal.token). CAM-004 replaces this with per-node issued
 * credentials without changing the route contract. Fails closed: a missing
 * or misconfigured server-side token refuses every request rather than
 * allowing it through, and human Laravel web sessions are never accepted
 * here — machine credentials and human sessions stay separate.
 */
class EnsureInternalServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('services.internal.token');

        if (! is_string($configured) || $configured === '') {
            return $this->error('INTERNAL_AUTH_NOT_CONFIGURED', 'Internal service authentication is not configured.', 503);
        }

        $provided = $request->header('X-Internal-Token');

        if (! is_string($provided) || ! hash_equals($configured, $provided)) {
            return $this->error('INTERNAL_TOKEN_INVALID', 'Invalid internal service token.', 401);
        }

        return $next($request);
    }

    private function error(string $code, string $message, int $status): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => ['code' => $code, 'fields' => new \stdClass],
        ], $status);
    }
}
