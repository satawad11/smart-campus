<?php

use App\Http\Controllers\Academic\ClassroomController;
use App\Http\Controllers\Academic\DepartmentController;
use App\Http\Controllers\Academic\ProgramController;
use App\Http\Controllers\Audit\AuditLogController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\LoginHistoryController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\PermissionController;
use App\Http\Controllers\Auth\RoleController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\UserController;
use App\Http\Controllers\Auth\UserSessionController;
use App\Http\Controllers\Camera\AiNodeController;
use App\Http\Controllers\Camera\AiNodeCredentialController;
use App\Http\Controllers\Camera\CameraController;
use App\Http\Controllers\Face\FaceDuplicateReviewController;
use App\Http\Controllers\Face\FaceEnrollmentController;
use App\Http\Controllers\Face\FaceEnrollmentVerificationController;
use App\Http\Controllers\Face\FaceLifecycleController;
use App\Http\Controllers\Face\FaceRecognitionTestController;
use App\Http\Controllers\Student\StudentController;
use App\Http\Controllers\Student\StudentImportController;
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

/*
| Academic Master Data (STD-005). Departments / Programs / Classrooms.
| UUID public identity; internal numeric ids are never accepted or exposed.
| Reads require academic.view; mutations require academic.manage. Master-data
| lifecycle is ACTIVE/INACTIVE (status), never a hard delete — so there is no
| DELETE route. Cross-entity consistency is enforced server-side.
*/
Route::middleware(['auth:web', 'active', 'session.active'])->group(function () {
    Route::get('/departments', [DepartmentController::class, 'index'])->middleware('permission:academic.view');
    Route::post('/departments', [DepartmentController::class, 'store'])->middleware('permission:academic.manage');
    Route::get('/departments/{department}', [DepartmentController::class, 'show'])->middleware('permission:academic.view');
    Route::put('/departments/{department}', [DepartmentController::class, 'update'])->middleware('permission:academic.manage');

    Route::get('/programs', [ProgramController::class, 'index'])->middleware('permission:academic.view');
    Route::post('/programs', [ProgramController::class, 'store'])->middleware('permission:academic.manage');
    Route::get('/programs/{program}', [ProgramController::class, 'show'])->middleware('permission:academic.view');
    Route::put('/programs/{program}', [ProgramController::class, 'update'])->middleware('permission:academic.manage');

    Route::get('/classrooms', [ClassroomController::class, 'index'])->middleware('permission:academic.view');
    Route::post('/classrooms', [ClassroomController::class, 'store'])->middleware('permission:academic.manage');
    Route::get('/classrooms/{classroom}', [ClassroomController::class, 'show'])->middleware('permission:academic.view');
    Route::put('/classrooms/{classroom}', [ClassroomController::class, 'update'])->middleware('permission:academic.manage');
});

/*
| Student CRUD / search (STD-007). {student} binds by StudentProfile uuid;
| internal ids are never accepted or exposed. Reads require student.view,
| create student.create, generic update student.update. Controllers are thin —
| all domain rules live in StudentService. The dedicated academic-status
| transition endpoint (PATCH /students/{uuid}/status) is STD-008. No DELETE
| (archive is STD-009); no import/export here.
*/
Route::middleware(['auth:web', 'active', 'session.active'])->group(function () {
    Route::get('/students', [StudentController::class, 'index'])->middleware('permission:student.view');
    Route::post('/students', [StudentController::class, 'store'])->middleware('permission:student.create');
    Route::get('/students/{student}', [StudentController::class, 'show'])->middleware('permission:student.view');
    Route::put('/students/{student}', [StudentController::class, 'update'])->middleware('permission:student.update');
    Route::patch('/students/{student}/status', [StudentController::class, 'changeStatus'])->middleware('permission:student.status.change');
    Route::get('/students/{student}/status-history', [StudentController::class, 'statusHistory'])->middleware('permission:student.view');
    Route::patch('/students/{student}/archive', [StudentController::class, 'archive'])->middleware('permission:student.archive');
    Route::post('/students/{student}/photo', [StudentController::class, 'uploadPhoto'])->middleware('permission:student.update');
    Route::delete('/students/{student}/photo', [StudentController::class, 'deletePhoto'])->middleware('permission:student.update');
});

/*
| Student Import (STD-012, STD-013). File upload, validation, preview, and execution.
| UUID public identity for import jobs. Requires permission:student.import.
*/
Route::middleware(['auth:web', 'active', 'session.active'])->group(function () {
    Route::post('/students/import', [StudentImportController::class, 'upload'])->middleware('permission:student.import');
    Route::get('/students/imports/{importJob}', [StudentImportController::class, 'show'])->middleware('permission:student.import');
    Route::get('/students/imports/{importJob}/errors', [StudentImportController::class, 'errors'])->middleware('permission:student.import');
    Route::get('/students/imports/{importJob}/preview', [StudentImportController::class, 'preview'])->middleware('permission:student.import');
    Route::post('/students/imports/{importJob}/process', [StudentImportController::class, 'process'])->middleware('permission:student.import');

    // Canonical API contract aliases (docs/02-api-contract.md §7)
    Route::get('/imports/{importJob}', [StudentImportController::class, 'show'])->middleware('permission:student.import');
    Route::get('/imports/{importJob}/errors', [StudentImportController::class, 'errors'])->middleware('permission:student.import');
    Route::post('/imports/{importJob}/process', [StudentImportController::class, 'process'])->middleware('permission:student.import');
});

/*
| Face Biometrics & Enrollment (FACE-009, FACE-010, docs/02-api-contract.md §8).
| Target identity is Person (person_id). Internal numeric IDs and raw embedding
| vectors are never accepted or exposed. Requires permission:face.enroll.
*/
Route::middleware(['auth:web', 'active', 'session.active'])->group(function () {
    Route::post('/persons/{person}/face/enroll', [FaceEnrollmentController::class, 'enroll'])->middleware('permission:face.enroll');
    Route::post('/faces/enroll', [FaceEnrollmentController::class, 'enroll'])->middleware('permission:face.enroll');

    // FACE-010: Face Enrollment Verification
    Route::post('/persons/{person}/face/verify', [FaceEnrollmentVerificationController::class, 'verifyByPerson'])->middleware('permission:face.enroll');
    Route::post('/faces/{faceProfile}/verify', [FaceEnrollmentVerificationController::class, 'verifyByProfile'])->middleware('permission:face.enroll');
    Route::post('/face-profiles/{faceProfile}/verify', [FaceEnrollmentVerificationController::class, 'verifyByProfile'])->middleware('permission:face.enroll');
    Route::post('/faces/verify', [FaceEnrollmentVerificationController::class, 'verify'])->middleware('permission:face.enroll');

    // FACE-011: Duplicate Face Review
    Route::get('/persons/{person}/face/duplicate-review', [FaceDuplicateReviewController::class, 'checkByPerson'])->middleware('permission:face.view');
    Route::get('/faces/{faceProfile}/duplicate-review', [FaceDuplicateReviewController::class, 'checkByProfile'])->middleware('permission:face.view');
    Route::get('/face-profiles/{faceProfile}/duplicate-review', [FaceDuplicateReviewController::class, 'checkByProfile'])->middleware('permission:face.view');
    Route::post('/faces/duplicate-reviews/{duplicateReview}/decision', [FaceDuplicateReviewController::class, 'submitDecision'])->middleware('permission:face.enroll');
    Route::post('/face-duplicate-reviews/{duplicateReview}/decision', [FaceDuplicateReviewController::class, 'submitDecision'])->middleware('permission:face.enroll');

    // FACE-012: Restricted Recognition Diagnostic Test
    Route::post('/faces/recognition-test', [FaceRecognitionTestController::class, 'test'])
        ->middleware(['permission:face.test', 'throttle:60,1']);

    // FACE-014 / FACE-017: Face Profile Query & Lifecycle (Revoke / Re-enroll)
    Route::get('/faces', [FaceLifecycleController::class, 'index'])->middleware('permission:face.view');
    Route::get('/faces/{faceProfile}', [FaceLifecycleController::class, 'show'])->middleware('permission:face.view');
    Route::get('/persons/{person}/faces', [FaceLifecycleController::class, 'personFaces'])->middleware('permission:face.view');
    Route::post('/faces/{faceProfile}/revoke', [FaceLifecycleController::class, 'revoke'])->middleware('permission:face.revoke');
    Route::post('/persons/{person}/face/revoke', [FaceLifecycleController::class, 'revokeByPerson'])->middleware('permission:face.revoke');
    Route::post('/faces/{faceProfile}/reenroll', [FaceLifecycleController::class, 'reenroll'])->middleware('permission:face.reenroll');
    Route::post('/persons/{person}/face/reenroll', [FaceLifecycleController::class, 'reenrollByPerson'])->middleware('permission:face.reenroll');
});

/*
| Camera CRUD / connection test / snapshot (CAM-002). {camera} binds by
| Camera uuid; internal ids are never accepted or exposed. RTSP credentials
| are encrypted at rest and never returned. No DELETE (camera lifecycle is
| enable/disable via the dedicated status operation). Runtime RTSP/video
| acquisition is CAM-005/006/007 — connection test/snapshot are bounded,
| non-biometric operational checks only.
*/
Route::middleware(['auth:web', 'active', 'session.active'])->group(function () {
    Route::get('/cameras', [CameraController::class, 'index'])->middleware('permission:camera.view');
    Route::post('/cameras', [CameraController::class, 'store'])->middleware('permission:camera.create');
    Route::get('/cameras/{camera}', [CameraController::class, 'show'])->middleware('permission:camera.view');
    Route::put('/cameras/{camera}', [CameraController::class, 'update'])->middleware('permission:camera.update');
    Route::patch('/cameras/{camera}/status', [CameraController::class, 'updateStatus'])->middleware('permission:camera.enable');
    Route::patch('/cameras/{camera}/roi', [CameraController::class, 'updateRoi'])->middleware('permission:camera.update');
    Route::post('/cameras/{camera}/test', [CameraController::class, 'test'])->middleware('permission:camera.test');
    Route::post('/cameras/{camera}/snapshot', [CameraController::class, 'snapshot'])->middleware('permission:camera.live');
    Route::get('/cameras/{camera}/health', [CameraController::class, 'health'])->middleware('permission:camera.view');
});

/*
| AI Node administrative CRUD (CAM-003) + credential lifecycle (CAM-004).
| {aiNode} binds by AiNode uuid. The node-facing check-in/heartbeat surface
| is separate, under /internal/v1 (routes/internal.php), authenticated by a
| per-node bearer credential (ai_node.token) — never this human-session
| stack. No DELETE.
*/
Route::middleware(['auth:web', 'active', 'session.active'])->group(function () {
    Route::get('/ai-nodes', [AiNodeController::class, 'index'])->middleware('permission:ai_node.view');
    Route::post('/ai-nodes', [AiNodeController::class, 'store'])->middleware('permission:ai_node.manage');
    Route::get('/ai-nodes/{aiNode}', [AiNodeController::class, 'show'])->middleware('permission:ai_node.view');
    Route::put('/ai-nodes/{aiNode}', [AiNodeController::class, 'update'])->middleware('permission:ai_node.manage');
    Route::patch('/ai-nodes/{aiNode}/status', [AiNodeController::class, 'updateStatus'])->middleware('permission:ai_node.manage');
    Route::get('/ai-nodes/{aiNode}/health', [AiNodeController::class, 'health'])->middleware('permission:ai_node.view');

    // Credential lifecycle (CAM-004). The plaintext token is returned once,
    // in the store() response, and never again.
    Route::get('/ai-nodes/{aiNode}/credentials', [AiNodeCredentialController::class, 'index'])->middleware('permission:ai_node.manage');
    Route::post('/ai-nodes/{aiNode}/credentials', [AiNodeCredentialController::class, 'store'])->middleware('permission:ai_node.manage');
    Route::post('/ai-nodes/{aiNode}/credentials/{credential}/revoke', [AiNodeCredentialController::class, 'revoke'])->middleware('permission:ai_node.manage');
});
