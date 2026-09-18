<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Base backend health endpoint (FND-003).
 * Unauthenticated liveness probe used by Docker/Nginx/monitoring.
 * Returns the standard API success envelope (PROJECT_RULES.md §6).
 * Deeper dependency (DB/Redis/storage) health checks arrive in FND-010.
 */
Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'Success',
        'data' => [
            'status' => 'ok',
            'service' => 'backend',
            'time' => now()->toIso8601String(),
        ],
        'meta' => null,
    ]);
});
