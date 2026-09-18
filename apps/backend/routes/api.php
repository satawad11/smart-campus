<?php

use App\Http\Controllers\Audit\AuditLogController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\LoginHistoryController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\PermissionController;
use App\Http\Controllers\Auth\RoleController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\UserController;
use App\Http\Controllers\Auth\UserSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| AI Smart Campus Security & Access Platform — API V1
|
*/

/*
| Authentication & Lifecycle (AUTH-005, AUTH-007, AUTH-012).
| First-party cookie session authentication. State-changing routes require CSRF protection.
*/
Route::prefix('auth')->group(function () {
    // Guest endpoints
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/mfa/verify', [MfaController::class, 'verify'])->middleware('throttle:mfa');

    // Authenticated endpoints
    Route::middleware(['auth:web', 'active', 'session.active'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);

        Route::middleware('throttle:mfa')->group(function () {
            Route::post('/mfa/setup', [MfaController::class, 'setup']);
            Route::post('/mfa/confirm', [MfaController::class, 'confirm']);
            Route::post('/mfa/disable', [MfaController::class, 'disable']);
        });

        // Own Session Management (AUTH-012)
        Route::get('/sessions', [SessionController::class, 'index']);
        Route::delete('/sessions/{session:uuid}', [SessionController::class, 'destroy']);
        Route::post('/sessions/revoke-others', [SessionController::class, 'revokeOthers']);
    });
});

/*
| Administrative User Management (AUTH-010, AUTH-012, AUTH-013). UUID public identity; numeric ids
| are never accepted or exposed. Authorization via permission middleware only.
*/
Route::middleware(['auth:web', 'active', 'session.active'])->group(function () {
    Route::get('/users', [UserController::class, 'index'])->middleware('permission:user.view');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:user.create');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:user.view');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:user.update');
    Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])->middleware('permission:user.disable');
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('permission:user.update');

    // Administrative Session Management (AUTH-012)
    Route::get('/users/{user}/sessions', [UserSessionController::class, 'index'])->middleware('permission:user.view');
    Route::delete('/users/{user}/sessions/{session:uuid}', [UserSessionController::class, 'destroy'])->middleware('permission:user.update');
    Route::post('/users/{user}/sessions/revoke-all', [UserSessionController::class, 'revokeAll'])->middleware('permission:user.update');

    // User Login History (AUTH-013)
    Route::get('/users/{user}/login-history', [LoginHistoryController::class, 'userHistory'])->middleware('permission:user.view');
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

/*
| Login History API (AUTH-013).
| Global login history query guarded by audit.view permission.
*/
Route::middleware(['auth:web', 'active', 'session.active'])->group(function () {
    Route::get('/login-history', [LoginHistoryController::class, 'index'])->middleware('permission:audit.view');
});

/*
| Audit Logs API (AUTH-014).
| Append-only security audit log query guarded by audit.view permission.
| Read-only; no update, delete, or purge endpoints exist.
*/
Route::middleware(['auth:web', 'active', 'session.active'])->group(function () {
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');
    Route::get('/audit-logs/{auditLog:uuid}', [AuditLogController::class, 'show'])->middleware('permission:audit.view');
});
