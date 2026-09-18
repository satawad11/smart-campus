<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\MfaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (Prefix: /api/v1)
|--------------------------------------------------------------------------
|
| Module 01: Authentication, Users, and RBAC routes.
|
*/

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('throttle:mfa')->group(function () {
        Route::post('/mfa/verify', [MfaController::class, 'verify']);
    });

    Route::middleware('auth:web')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::middleware('throttle:mfa')->group(function () {
            Route::post('/mfa/setup', [MfaController::class, 'setup']);
            Route::post('/mfa/confirm', [MfaController::class, 'confirm']);
            Route::post('/mfa/disable', [MfaController::class, 'disable']);
        });
    });
});
