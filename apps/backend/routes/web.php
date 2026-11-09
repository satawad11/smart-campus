<?php

use App\Http\Controllers\HealthController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Health endpoints (FND-010).
 * Unauthenticated probes for Docker/Nginx/monitoring. All use the standard API
 * envelope (PROJECT_RULES.md §6) and never expose secrets.
 *   /health            liveness (cheap 200)
 *   /health/database   PostgreSQL connectivity
 *   /health/redis      Redis connectivity
 *   /health/storage    object storage (S3/SeaweedFS) reachability
 *   /health/ai         AI service reachability
 *   /health/full       consolidated readiness (200 all-ok, 503 if any down)
 */
// V1 audit A-09: probes run without the session/cookie middleware, otherwise a
// Redis outage (session store) turned /health/redis into an opaque 500.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class, EncryptCookies::class, AddQueuedCookiesToResponse::class])
    ->group(function () {
        Route::get('/health', [HealthController::class, 'liveness']);
        Route::get('/health/database', [HealthController::class, 'database']);
        Route::get('/health/redis', [HealthController::class, 'redis']);
        Route::get('/health/storage', [HealthController::class, 'storage']);
        Route::get('/health/ai', [HealthController::class, 'ai']);
        Route::get('/health/full', [HealthController::class, 'full']);
    });
