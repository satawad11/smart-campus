<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureAiNodeToken;
use App\Http\Middleware\EnsureSessionActive;
use App\Http\Middleware\EnsureUserActive;
use App\Http\Middleware\RequirePermission;
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
        ]);

        $middleware->alias([
            'permission' => RequirePermission::class,
            'active' => EnsureUserActive::class,
            'session.active' => EnsureSessionActive::class,
            'ai_node.token' => EnsureAiNodeToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
