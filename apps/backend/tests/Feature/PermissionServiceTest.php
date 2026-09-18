<?php

namespace Tests\Feature;

use App\Enums\PermissionEffect;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\UserRole;
use App\Services\Auth\PermissionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PermissionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);

        // Register a test route protected by the permission middleware
        Route::get('/api/v1/test-protected-resource', function () {
            return response()->json([
                'success' => true,
                'message' => 'Access granted to protected resource',
                'data' => null,
                'meta' => null,
            ]);
        })->middleware(['auth:web', 'permission:student.view']);

        Route::get('/api/v1/test-multi-permission-resource', function () {
            return response()->json([
                'success' => true,
                'message' => 'Access granted',
                'data' => null,
                'meta' => null,
            ]);
        })->middleware(['auth:web', 'permission:student.view,camera.manage']);
    }

    public function test_user_with_active_role_permission_is_allowed(): void
    {
        $perm = Permission::create([
            'code' => 'student.view',
            'module' => 'student',
            'name' => 'View Students',
        ]);

        $role = Role::create([
            'code' => 'TEST_ROLE',
            'name' => 'Test Role',
            'status' => RoleStatus::ACTIVE,
            'system_role' => false,
        ]);
        $role->permissions()->attach($perm->id);

        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $user->roles()->attach($role->id);

        $this->assertTrue($this->permissionService->hasPermission($user, 'student.view'));
    }

    public function test_user_without_permission_is_denied(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);

        $this->assertFalse($this->permissionService->hasPermission($user, 'student.view'));
    }

    public function test_unauthenticated_request_remains_401(): void
    {
        $response = $this->getJson('/api/v1/test-protected-resource');

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
            'error' => [
                'code' => 'UNAUTHENTICATED',
            ],
        ]);
    }

    public function test_denied_authenticated_request_is_403_with_permission_denied_code(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);

        $response = $this->actingAs($user, 'web')->getJson('/api/v1/test-protected-resource');

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Permission denied',
            'error' => [
                'code' => 'PERMISSION_DENIED',
            ],
        ]);
    }

    public function test_unknown_permission_code_fails_closed(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);

        $this->assertFalse($this->permissionService->hasPermission($user, 'non_existent.permission.code'));
    }

    public function test_multiple_roles_combine_permissions(): void
    {
        $perm1 = Permission::create(['code' => 'student.view', 'module' => 'student', 'name' => 'View Students']);
        $perm2 = Permission::create(['code' => 'camera.manage', 'module' => 'camera', 'name' => 'Manage Cameras']);

        $role1 = Role::create(['code' => 'ROLE_1', 'name' => 'Role 1', 'status' => RoleStatus::ACTIVE]);
        $role1->permissions()->attach($perm1->id);

        $role2 = Role::create(['code' => 'ROLE_2', 'name' => 'Role 2', 'status' => RoleStatus::ACTIVE]);
        $role2->permissions()->attach($perm2->id);

        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $user->roles()->attach([$role1->id, $role2->id]);

        $this->assertTrue($this->permissionService->hasPermission($user, 'student.view'));
        $this->assertTrue($this->permissionService->hasPermission($user, 'camera.manage'));
        $this->assertFalse($this->permissionService->hasPermission($user, 'audit.view'));

        $effective = $this->permissionService->effectivePermissions($user);
        $this->assertEquals(['camera.manage', 'student.view'], $effective->toArray());
    }

    public function test_future_role_assignment_does_not_grant_permission(): void
    {
        $perm = Permission::create(['code' => 'student.view', 'module' => 'student', 'name' => 'View Students']);
        $role = Role::create(['code' => 'FUTURE_ROLE', 'name' => 'Future Role', 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach($perm->id);

        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);

        UserRole::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'valid_from' => now()->addDays(2),
            'valid_until' => now()->addDays(10),
        ]);

        $this->assertFalse($this->permissionService->hasPermission($user, 'student.view'));
    }

    public function test_expired_role_assignment_does_not_grant_permission(): void
    {
        $perm = Permission::create(['code' => 'student.view', 'module' => 'student', 'name' => 'View Students']);
        $role = Role::create(['code' => 'EXPIRED_ROLE', 'name' => 'Expired Role', 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach($perm->id);

        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);

        UserRole::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'valid_from' => now()->subDays(10),
            'valid_until' => now()->subDay(),
        ]);

        $this->assertFalse($this->permissionService->hasPermission($user, 'student.view'));
    }

    public function test_currently_valid_role_assignment_grants_permission(): void
    {
        $perm = Permission::create(['code' => 'student.view', 'module' => 'student', 'name' => 'View Students']);
        $role = Role::create(['code' => 'VALID_ROLE', 'name' => 'Valid Role', 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach($perm->id);

        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);

        UserRole::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDay(),
        ]);

        $this->assertTrue($this->permissionService->hasPermission($user, 'student.view'));
    }

    public function test_direct_allow_grants_permission_without_role_grant(): void
    {
        $perm = Permission::create(['code' => 'special.action', 'module' => 'special', 'name' => 'Special Action']);
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);

        UserPermission::create([
            'user_id' => $user->id,
            'permission_id' => $perm->id,
            'effect' => PermissionEffect::ALLOW,
        ]);

        $this->assertTrue($this->permissionService->hasPermission($user, 'special.action'));
    }

    public function test_direct_deny_overrides_role_grant(): void
    {
        $perm = Permission::create(['code' => 'student.delete', 'module' => 'student', 'name' => 'Delete Students']);
        $role = Role::create(['code' => 'ADMIN_ROLE', 'name' => 'Admin Role', 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach($perm->id);

        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $user->roles()->attach($role->id);

        // Verify initial permission from role
        $this->assertTrue($this->permissionService->hasPermission($user, 'student.delete'));

        // Add explicit direct DENY
        UserPermission::create([
            'user_id' => $user->id,
            'permission_id' => $perm->id,
            'effect' => PermissionEffect::DENY,
        ]);

        // Direct DENY must take highest precedence
        $this->assertFalse($this->permissionService->hasPermission($user, 'student.delete'));
        $this->assertNotContains('student.delete', $this->permissionService->effectivePermissions($user)->toArray());
    }

    public function test_inactive_locked_disabled_or_expired_user_is_denied(): void
    {
        $perm = Permission::create(['code' => 'student.view', 'module' => 'student', 'name' => 'View Students']);
        $role = Role::create(['code' => 'ACTIVE_ROLE', 'name' => 'Active Role', 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach($perm->id);

        $disabledUser = User::factory()->create(['status' => UserStatus::DISABLED]);
        $disabledUser->roles()->attach($role->id);

        $lockedUser = User::factory()->create(['status' => UserStatus::LOCKED]);
        $lockedUser->roles()->attach($role->id);

        $tempLockedUser = User::factory()->create(['status' => UserStatus::ACTIVE, 'locked_until' => now()->addMinutes(15)]);
        $tempLockedUser->roles()->attach($role->id);

        $expiredUser = User::factory()->create(['status' => UserStatus::EXPIRED]);
        $expiredUser->roles()->attach($role->id);

        $this->assertFalse($this->permissionService->hasPermission($disabledUser, 'student.view'));
        $this->assertFalse($this->permissionService->hasPermission($lockedUser, 'student.view'));
        $this->assertFalse($this->permissionService->hasPermission($tempLockedUser, 'student.view'));
        $this->assertFalse($this->permissionService->hasPermission($expiredUser, 'student.view'));

        $this->assertEmpty($this->permissionService->effectivePermissions($disabledUser));
        $this->assertEmpty($this->permissionService->effectivePermissions($lockedUser));
    }

    public function test_super_admin_is_authorized_through_seeded_permissions_matrix(): void
    {
        $this->seed(DatabaseSeeder::class);

        $superAdminRole = Role::where('code', 'SUPER_ADMIN')->firstOrFail();
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $user->roles()->attach($superAdminRole->id);

        // Must have seeded permissions
        $this->assertTrue($this->permissionService->hasPermission($user, 'dashboard.view'));
        $this->assertTrue($this->permissionService->hasPermission($user, 'user.create'));
        $this->assertTrue($this->permissionService->hasPermission($user, 'system.manage'));

        // If a direct DENY is applied, even SUPER_ADMIN gets denied (confirming no hardcoded bypass)
        $perm = Permission::where('code', 'user.create')->firstOrFail();
        UserPermission::create([
            'user_id' => $user->id,
            'permission_id' => $perm->id,
            'effect' => PermissionEffect::DENY,
        ]);

        $this->assertFalse($this->permissionService->hasPermission($user, 'user.create'));
    }

    public function test_system_admin_is_authorized_through_permission_matrix(): void
    {
        $this->seed(DatabaseSeeder::class);

        $systemAdminRole = Role::where('code', 'SYSTEM_ADMIN')->firstOrFail();
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $user->roles()->attach($systemAdminRole->id);

        $this->assertTrue($this->permissionService->hasPermission($user, 'user.create'));
        $this->assertTrue($this->permissionService->hasPermission($user, 'role.manage'));
    }

    public function test_permission_middleware_protects_route_correctly(): void
    {
        $perm = Permission::create(['code' => 'student.view', 'module' => 'student', 'name' => 'View Students']);
        $role = Role::create(['code' => 'STUDENT_VIEWER', 'name' => 'Viewer', 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach($perm->id);

        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $user->roles()->attach($role->id);

        $response = $this->actingAs($user, 'web')->getJson('/api/v1/test-protected-resource');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Access granted to protected resource',
        ]);
    }

    public function test_permission_middleware_multi_permission_route(): void
    {
        $perm = Permission::create(['code' => 'camera.manage', 'module' => 'camera', 'name' => 'Manage Cameras']);
        $role = Role::create(['code' => 'CAMERA_OPERATOR', 'name' => 'Operator', 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach($perm->id);

        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $user->roles()->attach($role->id);

        // User has camera.manage, which satisfies one of the route requirements (student.view,camera.manage)
        $response = $this->actingAs($user, 'web')->getJson('/api/v1/test-multi-permission-resource');

        $response->assertStatus(200);
    }

    public function test_architecture_rule_no_hardcoded_super_admin_role_name_bypass(): void
    {
        $serviceCode = file_get_contents(app_path('Services/Auth/PermissionService.php'));
        $middlewareCode = file_get_contents(app_path('Http/Middleware/RequirePermission.php'));

        $this->assertStringNotContainsString('SUPER_ADMIN', $serviceCode);
        $this->assertStringNotContainsString("hasRole('SUPER_ADMIN')", $serviceCode);
        $this->assertStringNotContainsString("hasRole('SUPER_ADMIN')", $middlewareCode);
        $this->assertStringNotContainsString('SUPER_ADMIN', $middlewareCode);
    }
}
