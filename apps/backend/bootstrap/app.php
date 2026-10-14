<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureAiNodeToken;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureSessionActive;
use App\Http\Middleware\EnsureUserActive;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeadersMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Machine-to-machine surface (CAM-003/CAM-004), deliberately
            // outside the human-session api/v1 stack (no cookies/CSRF/session
            // — see PROJECT_RULES §6). Auth is per-node bearer credential
            // (ai_node.token), applied per-route.
            Route::prefix('internal/v1')->group(base_path('routes/internal.php'));
        },
    )
    // EVT-013: registers POST /api/v1/broadcasting/auth for Echo's private-
    // channel handshake, under the same auth stack (session+CSRF via the
    // `api` middleware group below, then auth:web/active/session.active) as
    // every other authenticated route — this app has no separate `web`
    // group to hang the framework's default broadcasting route on.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:web', 'active', 'session.active']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Canonical Laravel session+CSRF cookie stack (same composition and
        // order as the framework's own default `web` group), appended to
        // `api` so this SPA can use cookie-session auth over /api/v1
        // without introducing Sanctum. EncryptCookies/AddQueuedCookiesToResponse
        // MUST run outermost, before StartSession/ValidateCsrfToken: without
        // them, the XSRF-TOKEN cookie is set in plaintext, but
        // ValidateCsrfToken::getTokenFromRequest() always decrypts the
        // X-XSRF-TOKEN header the browser echoes back — that decrypt fails
        // against a plaintext value, so every real login silently 419s
        // (invisible to the test suite, which bypasses CSRF entirely via
        // Application::runningUnitTests()).
        $middleware->api(append: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ValidateCsrfToken::class,
            SecurityHeadersMiddleware::class,
        ]);

        $middleware->web(append: [
            SecurityHeadersMiddleware::class,
        ]);

        // Security baseline: general /api/v1 rate limit (RateLimiter 'api',
        // AppServiceProvider). /internal/v1 isn't in this group.
        $middleware->throttleApi();

        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));

        $middleware->alias([
            'permission' => RequirePermission::class,
            'active' => EnsureUserActive::class,
            'session.active' => EnsureSessionActive::class,
            'ai_node.token' => EnsureAiNodeToken::class,
            'feature' => EnsureFeatureEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // V1 audit A-24: ApiException is an expected business outcome (409/422,
        // e.g. EVENT_ALREADY_EXISTS), not a fault. Logging it at ERROR made it
        // indistinguishable from real failures for log-based alerting.
        $exceptions->level(ApiException::class, LogLevel::INFO);

        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*') || $request->is('internal/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'fields' => $e->errors(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->is('internal/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                    'error' => [
                        'code' => 'UNAUTHENTICATED',
                        'fields' => new stdClass,
                    ],
                ], 401);
            }
        });

        $exceptions->render(function (HttpException $e, $request) {
            if ($e->getStatusCode() === 419 && ($request->is('api/*') || $request->is('internal/*') || $request->expectsJson())) {
                return response()->json([
                    'success' => false,
                    'message' => 'CSRF token mismatch',
                    'error' => [
                        'code' => 'CSRF_MISMATCH',
                        'fields' => new stdClass,
                    ],
                ], 419);
            }
        });

        $exceptions->render(function (ApiException $e, $request) {
            if ($request->is('api/*') || $request->is('internal/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error' => [
                        'code' => $e->errorCode,
                        'fields' => new stdClass,
                    ],
                ], $e->status);
            }
        });

        $exceptions->render(function (TokenMismatchException $e, $request) {
            if ($request->is('api/*') || $request->is('internal/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'CSRF token mismatch',
                    'error' => [
                        'code' => 'CSRF_MISMATCH',
                        'fields' => new stdClass,
                    ],
                ], 419);
            }
        });
    })->create();
