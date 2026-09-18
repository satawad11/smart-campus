<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

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
 *   /health/storage    object storage (S3/MinIO) reachability
 *   /health/ai         AI service reachability
 *   /health/full       consolidated readiness (200 all-ok, 503 if any down)
 */
Route::get('/health', [HealthController::class, 'liveness']);
Route::get('/health/database', [HealthController::class, 'database']);
Route::get('/health/redis', [HealthController::class, 'redis']);
Route::get('/health/storage', [HealthController::class, 'storage']);
Route::get('/health/ai', [HealthController::class, 'ai']);
Route::get('/health/full', [HealthController::class, 'full']);
