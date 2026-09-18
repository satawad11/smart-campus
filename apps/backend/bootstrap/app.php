<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureSessionActive;
use App\Http\Middleware\EnsureUserActive;
use App\Http\Middleware\RequirePermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            StartSession::class,
            ValidateCsrfToken::class,
        ]);

        $middleware->alias([
            'permission' => RequirePermission::class,
            'active' => EnsureUserActive::class,
            'session.active' => EnsureSessionActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
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
            if ($request->is('api/*') || $request->expectsJson()) {
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
            if ($e->getStatusCode() === 419 && ($request->is('api/*') || $request->expectsJson())) {
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
            if ($request->is('api/*') || $request->expectsJson()) {
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
            if ($request->is('api/*') || $request->expectsJson()) {
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
