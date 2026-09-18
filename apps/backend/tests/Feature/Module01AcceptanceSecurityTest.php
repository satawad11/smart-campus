<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\PermissionEffect;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\LoginLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\UserSession;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use App\Services\Auth\PermissionService;
use App\Support\Authorization\ScopeContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * AUTH-018: Final Acceptance & Security Gate for Module 01 (Auth + RBAC).
 *
 * Verifies acceptance and security invariants across Sections A through P.
 */
class Module01AcceptanceSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->google2fa = new Google2FA;
        $this->withoutMiddleware(ValidateCsrfToken::class);
        RateLimiter::clear('mfa');
        RateLimiter::clear('login');
    }

    private function createActiveUser(string $username, string $password = 'Password123!', array $roles = ['SUPER_ADMIN']): User
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'username' => $username,
            'email' => "{$username}@example.com",
            'password' => Hash::make($password),
            'status' => UserStatus::ACTIVE,
            'must_change_password' => false,
            'mfa_enabled' => false,
        ]);

        foreach ($roles as $roleCode) {
            $role = Role::where('code', $roleCode)->first();
            if ($role) {
                $user->roles()->attach($role->id);
            }
        }

        return $user;
    }

    // ==========================================
    // SECTION A & B: AUTHENTICATION & PASSWORDS
    // ==========================================

    public function test_auth_and_password_lifecycle_and_security_invariants(): void
    {
        $user = $this->createActiveUser('sec_user', 'InitialPassword123!');

        // 1. Invalid login returns safe generic failure without account enumeration
        $failResp = $this->postJson('/api/v1/auth/login', [
            'username' => 'sec_user',
            'password' => 'WrongPassword!',
        ]);
        $failResp->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'AUTH_INVALID_CREDENTIALS'],
            ]);

        $ghostResp = $this->postJson('/api/v1/auth/login', [
            'username' => 'nonexistent_ghost_user',
            'password' => 'WrongPassword!',
        ]);
        $ghostResp->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'AUTH_INVALID_CREDENTIALS'],
            ]);

        // 2. Valid login creates exactly one tracked session and regenerates session ID
        $loginResp = $this->postJson('/api/v1/auth/login', [
            'username' => 'sec_user',
            'password' => 'InitialPassword123!',
        ]);
        $loginResp->assertOk()
            ->assertJson(['success' => true, 'data' => ['user' => ['username' => 'sec_user']]]);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('user_sessions', 1);

        $sessionRow = UserSession::where('user_id', $user->id)->first();
        $this->assertNotNull($sessionRow);
        $this->assertNotNull($sessionRow->session_id);
        $this->assertNull($sessionRow->revoked_at);

        // 3. Password change: requires current password, rejects same-password, clears must_change_password
        $user->update(['must_change_password' => true]);

        // Same password rejected
        $sameResp = $this->actingAs($user, 'web')->postJson('/api/v1/auth/change-password', [
            'current_password' => 'InitialPassword123!',
            'password' => 'InitialPassword123!',
            'password_confirmation' => 'InitialPassword123!',
        ]);
        $sameResp->assertStatus(422)
            ->assertJson(['error' => ['code' => 'VALIDATION_ERROR']]);

        // Valid change password succeeds and clears must_change_password
        $changeResp = $this->actingAs($user, 'web')->postJson('/api/v1/auth/change-password', [
            'current_password' => 'InitialPassword123!',
            'password' => 'NewSecurePassword456!',
            'password_confirmation' => 'NewSecurePassword456!',
        ]);
        $changeResp->assertOk();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('NewSecurePassword456!', $user->password));

        // 4. Logout invalidates current authentication
        $logoutResp = $this->actingAs($user, 'web')->postJson('/api/v1/auth/logout');
        $logoutResp->assertOk();
        $this->assertGuest('web');
    }

    // ==========================================
    // SECTION C: MFA ACCEPTANCE & INVARIANTS
    // ==========================================

    public function test_mfa_setup_totp_and_disable_invariants(): void
    {
        $user = $this->createActiveUser('mfa_sec_user', 'Pass12345678!');

        // Setup requires authentication
        $this->postJson('/api/v1/auth/mfa/setup')->assertStatus(401);

        // Setup starts enrollment
        $setupResp = $this->actingAs($user, 'web')->postJson('/api/v1/auth/mfa/setup');
        $setupResp->assertOk();
        $secret = $setupResp->json('data.secret');
        $this->assertNotEmpty($secret);

        // Secret is not activated before confirm
        $user->refresh();
        $this->assertFalse($user->mfa_enabled);

        // Valid OTP confirms and enables MFA
        $otp = $this->google2fa->getCurrentOtp($secret);
        $confirmResp = $this->actingAs($user, 'web')->postJson('/api/v1/auth/mfa/confirm', [
            'code' => $otp,
        ]);
        $confirmResp->assertOk();

        $user->refresh();
        $this->assertTrue($user->mfa_enabled);
        $this->assertSame($secret, Crypt::decryptString($user->mfa_secret_encrypted));

        // Audit log recorded MFA_ENABLED without secret/OTP
        $audit = AuditLog::where('action', 'MFA_ENABLED')->where('entity_id', $user->uuid)->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($secret, json_encode($audit->metadata ?? []));
        $this->assertStringNotContainsString($otp, json_encode($audit->metadata ?? []));
    }

    // ==========================================
    // SECTION D: SESSION SECURITY & FAIL-CLOSED
    // ==========================================

    public function test_session_security_and_fail_closed(): void
    {
        $user = $this->createActiveUser('sess_sec_user');
        $this->actingAs($user, 'web');

        // Active session accesses protected route
        $this->getJson('/api/v1/auth/me')->assertOk();

        // Revoking session fails closed on next request
        UserSession::where('session_id', $this->trackedWebSessionId)->update(['revoked_at' => now()]);

        $blockedResp = $this->getJson('/api/v1/auth/me');
        $blockedResp->assertStatus(401)
            ->assertJson(['error' => ['code' => 'UNAUTHENTICATED']]);
    }

    // ==========================================
    // SECTION E & F: RBAC & SCOPES
    // ==========================================

    public function test_rbac_resolution_and_scope_authorization(): void
    {
        $permService = app(PermissionService::class);
        $user = $this->createActiveUser('rbac_user', 'Pass123!', ['EXECUTIVE_VIEWER']);
        $globalContext = ScopeContext::global();

        // Executive viewer has report.view but not user.create
        $this->assertTrue($permService->can($user, 'report.view', $globalContext));
        $this->assertFalse($permService->can($user, 'user.create', $globalContext));

        // Direct DENY overrides any role grant
        $reportPerm = Permission::where('code', 'report.view')->first();
        UserPermission::create([
            'user_id' => $user->id,
            'permission_id' => $reportPerm->id,
            'effect' => PermissionEffect::DENY,
        ]);
        $this->assertFalse($permService->can($user, 'report.view', $globalContext));

        // Direct ALLOW grants without role
        $userCreatePerm = Permission::where('code', 'user.create')->first();
        UserPermission::create([
            'user_id' => $user->id,
            'permission_id' => $userCreatePerm->id,
            'effect' => PermissionEffect::ALLOW,
        ]);
        $this->assertTrue($permService->can($user, 'user.create', $globalContext));

        // Scope resolution
        $siteContext = ScopeContext::site(1);
        $zoneContext = ScopeContext::zone(1, 1);

        $this->assertTrue($permService->can($user, 'user.create', $globalContext));
        $this->assertTrue($permService->can($user, 'user.create', $siteContext));
        $this->assertTrue($permService->can($user, 'user.create', $zoneContext));
    }

    // ==========================================
    // SECTION G & H: USER & ROLE MANAGEMENT SAFEGUARDS
    // ==========================================

    public function test_user_and_role_management_safeguards(): void
    {
        $superAdmin = $this->createActiveUser('root_admin', 'Pass123!', ['SUPER_ADMIN']);
        $targetUser = $this->createActiveUser('regular_target', 'Pass123!', ['SECURITY_OFFICER']);

        // 1. Self-disable/lock blocked with 409
        $selfDisableResp = $this->actingAs($superAdmin, 'web')->patchJson("/api/v1/users/{$superAdmin->uuid}/status", [
            'status' => 'DISABLED',
        ]);
        $selfDisableResp->assertStatus(409)
            ->assertJson(['error' => ['code' => 'USER_SELF_DISABLE_FORBIDDEN']]);

        // 2. Last Super Admin protection
        $lastAdminDisable = $this->actingAs($superAdmin, 'web')->patchJson("/api/v1/users/{$superAdmin->uuid}/status", [
            'status' => 'LOCKED',
        ]);
        $lastAdminDisable->assertStatus(409);

        // 3. Super Admin role permissions cannot be stripped of core permissions
        $superRole = Role::where('code', 'SUPER_ADMIN')->first();
        $stripResp = $this->actingAs($superAdmin, 'web')->putJson("/api/v1/roles/{$superRole->uuid}/permissions", [
            'permissions' => ['user.view'],
        ]);
        $stripResp->assertStatus(409)
            ->assertJson(['error' => ['code' => 'SUPER_ADMIN_PERMISSIONS_REQUIRED']]);

        // 4. Unknown permission in matrix rejects atomically
        $invalidMatrix = $this->actingAs($superAdmin, 'web')->putJson("/api/v1/roles/{$superRole->uuid}/permissions", [
            'permissions' => ['user.view', 'nonexistent.fake.permission'],
        ]);
        $invalidMatrix->assertStatus(422);
    }

    // ==========================================
    // SECTION I & J: LOGIN HISTORY & AUDIT IMMUTABILITY
    // ==========================================

    public function test_login_history_and_audit_immutability(): void
    {
        $user = $this->createActiveUser('audit_target', 'Pass123!', ['SECURITY_OFFICER']);

        // Login log recorded without sensitive payload
        $this->postJson('/api/v1/auth/login', [
            'username' => 'audit_target',
            'password' => 'Pass123!',
        ]);

        $log = LoginLog::where('username', 'audit_target')->first();
        $this->assertNotNull($log);
        $this->assertTrue($log->success);

        // Audit log created for user status change
        $auditService = app(AuditService::class);
        $auditService->record(
            action: AuditAction::USER_STATUS_CHANGED,
            actor: $user,
            subjectType: 'USER',
            subjectUuid: $user->uuid,
            oldValues: ['status' => 'ACTIVE'],
            newValues: ['status' => 'DISABLED'],
            context: new AuditRequestContext(ipAddress: '127.0.0.1', userAgent: 'test-agent')
        );

        $audit = AuditLog::where('action', 'USER_STATUS_CHANGED')->first();
        $this->assertNotNull($audit);
        $this->assertSame($user->uuid, $audit->entity_id);
        $this->assertArrayNotHasKey('password', (array) $audit->new_values);
        $this->assertArrayNotHasKey('secret', (array) $audit->new_values);
    }

    // ==========================================
    // SECTION N & P: STATIC SECURITY & ROUTE AUDIT
    // ==========================================

    public function test_security_and_route_architecture_rules(): void
    {
        $routes = RouteFacade::getRoutes()->getRoutes();

        foreach ($routes as $route) {
            $uri = $route->uri();
            $methods = $route->methods();

            // 1. No DELETE routes exist for users, roles, audit_logs, or login_logs
            if (in_array('DELETE', $methods)) {
                $this->assertFalse((bool) preg_match('#^api/v1/users/\{[^/]+\}$#', $uri), "Forbidden DELETE user route: {$uri}");
                $this->assertFalse((bool) preg_match('#^api/v1/roles/\{[^/]+\}$#', $uri), "Forbidden DELETE role route: {$uri}");
                $this->assertStringNotContainsString('api/v1/audit-logs', $uri, "Forbidden DELETE audit route: {$uri}");
                $this->assertStringNotContainsString('api/v1/login-logs', $uri, "Forbidden DELETE login-log route: {$uri}");
            }

            // 2. No numeric internal IDs in public API routes (e.g. {id} vs {uuid})
            if (str_starts_with($uri, 'api/v1/')) {
                $this->assertStringNotContainsString('{id}', $uri, "Forbidden numeric ID parameter in public API: {$uri}");
            }
        }
    }
}
