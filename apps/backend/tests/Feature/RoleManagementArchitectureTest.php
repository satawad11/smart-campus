<?php

namespace Tests\Feature;

use App\Enums\PermissionEffect;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\UserRole;
use App\Services\Auth\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AUTH-011 architecture / security guardrails and RBAC regression (44-53).
 */
class RoleManagementArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private function routeExists(string $method, string $uriContains): bool
    {
        foreach (Route::getRoutes() as $route) {
            if (in_array($method, $route->methods(), true) && str_contains($route->uri(), $uriContains)) {
                return true;
            }
        }

        return false;
    }

    public function test_no_delete_role_route(): void // 28/protection
    {
        $this->assertFalse($this->routeExists('DELETE', 'roles'));
    }

    public function test_no_permission_crud_routes(): void // 51
    {
        $this->assertFalse($this->routeExists('POST', 'permissions'));
        $this->assertFalse($this->routeExists('PUT', 'api/v1/permissions'));
        $this->assertFalse($this->routeExists('DELETE', 'permissions'));
    }

    public function test_no_user_role_assignment_routes(): void // 52
    {
        $this->assertFalse($this->routeExists('POST', 'users/{user}/roles'));
        $this->assertFalse($this->routeExists('PUT', 'users/{user}/roles'));
        $this->assertFalse($this->routeExists('DELETE', 'users/{user}/roles'));
        $this->assertFalse($this->routeExists('POST', 'roles/{role}/users'));
    }

    public function test_no_super_admin_authorization_bypass(): void // 44
    {
        $src = file_get_contents(base_path('app/Services/Auth/PermissionService.php'));
        $this->assertStringNotContainsString('SUPER_ADMIN', $src);
        $this->assertStringNotContainsString('SYSTEM_ADMIN', $src);
    }

    public function test_no_future_domain_modules(): void // 53
    {
        foreach (['Person', 'Site', 'Zone', 'Camera', 'Door', 'Visitor', 'Vehicle'] as $model) {
            $this->assertFileDoesNotExist(base_path("app/Models/{$model}.php"));
        }
    }

    public function test_resources_do_not_expose_numeric_ids(): void // 9/14/22
    {
        $role = Role::create(['code' => 'RES_TEST', 'name' => 'Res', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $perm = Permission::create(['code' => 'x.view', 'module' => 'x', 'name' => 'X']);

        $roleArr = (new RoleResource($role->loadCount('permissions')))->toArray(request());
        $permArr = (new PermissionResource($perm))->toArray(request());

        $this->assertArrayNotHasKey('id', $roleArr);
        $this->assertArrayHasKey('uuid', $roleArr);
        $this->assertArrayNotHasKey('id', $permArr);
        $this->assertArrayHasKey('code', $permArr);
    }

    // ---- RBAC regression (45-49) ----

    public function test_direct_deny_overrides_role_permission(): void // 45
    {
        $svc = app(PermissionService::class);
        $perm = Permission::create(['code' => 'student.view', 'module' => 'student', 'name' => 'View']);
        $role = Role::create(['code' => 'R', 'name' => 'R', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach($perm->id);
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $this->assertTrue($svc->hasPermission($user, 'student.view'));

        UserPermission::create(['user_id' => $user->id, 'permission_id' => $perm->id, 'effect' => PermissionEffect::DENY]);
        $this->assertFalse($svc->hasPermission($user, 'student.view'));
    }

    public function test_expired_assignment_and_inactive_user_grant_nothing(): void // 47/49
    {
        $svc = app(PermissionService::class);
        $perm = Permission::create(['code' => 'student.view', 'module' => 'student', 'name' => 'View']);
        $role = Role::create(['code' => 'R', 'name' => 'R', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach($perm->id);

        // Expired assignment.
        $u1 = User::factory()->create(['status' => UserStatus::ACTIVE]);
        UserRole::create(['user_id' => $u1->id, 'role_id' => $role->id, 'valid_until' => now()->subDay()]);
        $this->assertFalse($svc->hasPermission($u1, 'student.view'));

        // Inactive user.
        $u2 = User::factory()->create(['status' => UserStatus::DISABLED]);
        UserRole::create(['user_id' => $u2->id, 'role_id' => $role->id]);
        $this->assertFalse($svc->hasPermission($u2, 'student.view'));
    }
}
