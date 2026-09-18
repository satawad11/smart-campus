<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\PermissionController;
use App\Http\Controllers\Auth\RoleController;
use App\Http\Controllers\Auth\UserController;
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

    Route::middleware(['auth:web', 'active', 'session.active'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::middleware('throttle:mfa')->group(function () {
            Route::post('/mfa/setup', [MfaController::class, 'setup']);
            Route::post('/mfa/confirm', [MfaController::class, 'confirm']);
            Route::post('/mfa/disable', [MfaController::class, 'disable']);
        });
    });
});

/*
| Administrative User Management (AUTH-010). UUID public identity; numeric ids
| are never accepted or exposed. Authorization via permission middleware only.
*/
Route::middleware(['auth:web', 'active', 'session.active'])->group(function () {
    Route::get('/users', [UserController::class, 'index'])->middleware('permission:user.view');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:user.create');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:user.view');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:user.update');
    Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])->middleware('permission:user.disable');
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('permission:user.update');
});

/*
| Administrative Role & Permission Management (AUTH-011). UUID public identity;
| authorization via permission middleware only (role.view / role.manage).
| Permissions catalog is read-only; there is no DELETE role route.
*/
Route::middleware(['auth:web', 'active', 'session.active'])->group(function () {
    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:role.view');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:role.manage');
    Route::get('/roles/{role}', [RoleController::class, 'show'])->middleware('permission:role.view');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:role.manage');
    Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->middleware('permission:role.manage');

    Route::get('/permissions', [PermissionController::class, 'index'])->middleware('permission:role.view');
});
