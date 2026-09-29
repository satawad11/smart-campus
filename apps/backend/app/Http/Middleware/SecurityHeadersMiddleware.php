<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production Security Headers Middleware (SEC-003).
 * Hardens responses against clickjacking, MIME sniffing, XSS, and unapproved framing.
 */
class SecurityHeadersMiddleware
{
    /**
     * Handle an incoming request and attach hardened security headers.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Frame protection (Anti-Clickjacking)
        $response->headers->set('X-Frame-Options', 'DENY');

        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Disable legacy buggy browser XSS auditor to prevent vulnerability
        $response->headers->set('X-XSS-Protection', '0');

        // Restrict referrer leakage
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Restrict browser permissions/hardware access
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');

        // Content Security Policy
        $csp = "default-src 'self'; "
            ."script-src 'self' 'unsafe-inline'; "
            ."style-src 'self' 'unsafe-inline'; "
            ."img-src 'self' data: blob:; "
            ."connect-src 'self' ws: wss:; "
            ."font-src 'self'; "
            ."object-src 'none'; "
            ."frame-ancestors 'none';";
        $response->headers->set('Content-Security-Policy', $csp);

        // HSTS when serving over HTTPS
        if ($request->isSecure() || $request->server('HTTPS') === 'on' || strtolower((string) $request->header('X-Forwarded-Proto')) === 'https' || app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }
}
