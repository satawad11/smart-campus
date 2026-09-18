<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Auth\PermissionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        // Canonical RBAC reference data (roles + 44 permissions + matrix).
        $this->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);
    }

    /** @param  list<string>  $codes */
    private function admin(array $codes = ['role.view', 'role.manage']): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'ADMIN_'.uniqid(), 'name' => 'Admin', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    // ---- Role list / show (1-10) ----

    public function test_list_auth_and_permission(): void
    {
        $this->getJson('/api/v1/roles')->assertStatus(401); // 1
        $this->actingAs($this->admin([]), 'web')->getJson('/api/v1/roles')->assertStatus(403); // 2
    }

    public function test_list_features(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin, 'web')->getJson('/api/v1/roles'); // 3
        $res->assertOk();
        $this->assertSame(20, $res->json('meta.pagination.per_page')); // 4
        foreach ($res->json('data') as $row) { // 9 no numeric ids
            $this->assertArrayNotHasKey('id', $row);
            $this->assertArrayHasKey('uuid', $row);
            $this->assertArrayHasKey('permissions_count', $row);
        }

        // 5 keyword
        $this->assertGreaterThanOrEqual(1, $this->actingAs($admin, 'web')->getJson('/api/v1/roles?keyword=SUPER')->json('meta.pagination.total'));
        // 6 status filter
        $this->actingAs($admin, 'web')->getJson('/api/v1/roles?status=ACTIVE')->assertOk();
        // 7 system_role filter
        $sys = $this->actingAs($admin, 'web')->getJson('/api/v1/roles?system_role=1');
        foreach ($sys->json('data') as $row) {
            $this->assertTrue($row['system_role']);
        }
        // 8 sort whitelist
        $this->actingAs($admin, 'web')->getJson('/api/v1/roles?sort=password')->assertStatus(422);
        $this->actingAs($admin, 'web')->getJson('/api/v1/roles?sort=name&order=desc')->assertOk();
        // per_page max
        $this->actingAs($admin, 'web')->getJson('/api/v1/roles?per_page=500')->assertStatus(422);
    }

    public function test_show_and_unknown_uuid(): void
    {
        $admin = $this->admin();
        $role = Role::where('code', 'SUPER_ADMIN')->first();

        $res = $this->actingAs($admin, 'web')->getJson("/api/v1/roles/{$role->uuid}");
        $res->assertOk();
        $this->assertArrayNotHasKey('id', $res->json('data'));
        $this->assertIsArray($res->json('data.permissions')); // detail includes codes

        $this->actingAs($admin, 'web')->getJson('/api/v1/roles/00000000-0000-0000-0000-000000000000')->assertStatus(404); // 10
    }

    // ---- Permission catalog (11-15) ----

    public function test_permission_catalog(): void
    {
        $admin = $this->admin();

        $this->actingAs($this->admin([]), 'web')->getJson('/api/v1/permissions')->assertStatus(403); // 11

        $res = $this->actingAs($admin, 'web')->getJson('/api/v1/permissions');
        $res->assertOk();
        $codes = array_column($res->json('data'), 'code');
        $this->assertContains('student.view', $codes); // 12 stable codes
        foreach ($res->json('data') as $row) { // 14 no numeric ids
            $this->assertArrayNotHasKey('id', $row);
            $this->assertArrayHasKey('module', $row);
        }
        // 13 module filter
        $userMod = $this->actingAs($admin, 'web')->getJson('/api/v1/permissions?module=user');
        foreach ($userMod->json('data') as $row) {
            $this->assertSame('user', $row['module']);
        }
        // 15 future disabled-module permissions absent
        foreach (['door.', 'visitor.', 'vehicle.', 'alpr.', 'ai_assistant.'] as $future) {
            foreach ($codes as $code) {
                $this->assertStringStartsNotWith($future, $code);
            }
        }
    }

    // ---- Create role (16-22) ----

    public function test_create_role(): void
    {
        $admin = $this->admin();

        $this->actingAs($this->admin(['role.view']), 'web')->postJson('/api/v1/roles', ['code' => 'X', 'name' => 'X'])->assertStatus(403); // 16

        $res = $this->actingAs($admin, 'web')->postJson('/api/v1/roles', [
            'code' => 'custom_helper', 'name' => 'Custom Helper', 'description' => 'desc',
        ]);
        $res->assertStatus(201); // 17
        $this->assertNotEmpty($res->json('data.uuid')); // 18
        $this->assertArrayNotHasKey('id', $res->json('data')); // 22
        $role = Role::where('code', 'CUSTOM_HELPER')->first(); // normalized uppercase
        $this->assertNotNull($role);
        $this->assertFalse($role->system_role);

        // 19 duplicate code
        $this->actingAs($admin, 'web')->postJson('/api/v1/roles', ['code' => 'CUSTOM_HELPER', 'name' => 'Dup'])->assertStatus(422);
        // 20 malformed code
        $this->actingAs($admin, 'web')->postJson('/api/v1/roles', ['code' => 'bad code!', 'name' => 'Bad'])->assertStatus(422);
        $this->actingAs($admin, 'web')->postJson('/api/v1/roles', ['code' => '   ', 'name' => 'Blank'])->assertStatus(422);
        // 21 system_role injection
        $this->actingAs($admin, 'web')->postJson('/api/v1/roles', ['code' => 'PRIV', 'name' => 'Priv', 'system_role' => true])->assertStatus(422);
    }

    // ---- Update role (23-28) ----

    public function test_update_role(): void
    {
        $admin = $this->admin();
        $custom = Role::create(['code' => 'CUSTOM_A', 'name' => 'A', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);

        $this->actingAs($this->admin(['role.view']), 'web')->putJson("/api/v1/roles/{$custom->uuid}", ['name' => 'B'])->assertStatus(403); // 23

        // 24 name/description/status update
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$custom->uuid}", [
            'name' => 'Renamed', 'description' => 'new', 'status' => 'DISABLED',
        ])->assertOk();
        $custom->refresh();
        $this->assertSame('Renamed', $custom->name);
        $this->assertSame(RoleStatus::DISABLED, $custom->status);

        // 25/26 code immutable (custom + system)
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$custom->uuid}", ['code' => 'NEWCODE'])->assertStatus(422);
        $superAdmin = Role::where('code', 'SUPER_ADMIN')->first();
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$superAdmin->uuid}", ['code' => 'ROOT'])->assertStatus(422);
        // 27 system_role flag immutable
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$custom->uuid}", ['system_role' => true])->assertStatus(422);
    }

    // ---- Permission matrix (29-38) ----

    public function test_permission_matrix(): void
    {
        $admin = $this->admin();
        $role = Role::create(['code' => 'MATRIX_ROLE', 'name' => 'Matrix', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);

        $this->actingAs($this->admin(['role.view']), 'web')->putJson("/api/v1/roles/{$role->uuid}/permissions", ['permissions' => []])->assertStatus(403); // 29

        // 30/31 apply by code
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$role->uuid}/permissions", [
            'permissions' => ['dashboard.view', 'student.view', 'student.view'], // 33 duplicate
        ])->assertOk();
        $this->assertEqualsCanonicalizing(['dashboard.view', 'student.view'], $role->permissions()->pluck('code')->all());

        // 32/34 unknown permission → whole update fails (atomic)
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$role->uuid}/permissions", [
            'permissions' => ['dashboard.view', 'nonexistent.permission'],
        ])->assertStatus(422);
        $this->assertEqualsCanonicalizing(['dashboard.view', 'student.view'], $role->fresh()->permissions()->pluck('code')->all());

        // 38 scope assignments not modified
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'site_id' => 10, 'zone_id' => 5]);
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$role->uuid}/permissions", ['permissions' => ['event.view']])->assertOk();
        $assignment = UserRole::where('user_id', $user->id)->where('role_id', $role->id)->first();
        $this->assertSame(10, (int) $assignment->site_id);
        $this->assertSame(5, (int) $assignment->zone_id);
    }

    public function test_role_changes_affect_authorization_immediately(): void
    {
        $svc = app(PermissionService::class);
        $admin = $this->admin();
        $role = Role::create(['code' => 'IMMEDIATE', 'name' => 'Imm', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::where('code', 'student.view')->value('id'));
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        $this->assertTrue($svc->hasPermission($user, 'student.view')); // 35 baseline

        // Remove permission via API -> next check reflects it.
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$role->uuid}/permissions", ['permissions' => []])->assertOk();
        $this->assertFalse($svc->hasPermission($user, 'student.view'));

        // Re-add and disable role -> 36 disabled role grants nothing.
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$role->uuid}/permissions", ['permissions' => ['student.view']])->assertOk();
        $this->assertTrue($svc->hasPermission($user, 'student.view'));
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$role->uuid}", ['status' => 'DISABLED'])->assertOk();
        $this->assertFalse($svc->hasPermission($user, 'student.view'));

        // 37 re-enable resumes current matrix.
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$role->uuid}", ['status' => 'ACTIVE'])->assertOk();
        $this->assertTrue($svc->hasPermission($user, 'student.view'));
    }

    // ---- SUPER_ADMIN protection (39-43) ----

    public function test_super_admin_protection(): void
    {
        $admin = $this->admin();
        $superAdmin = Role::where('code', 'SUPER_ADMIN')->first();

        // 39 cannot be disabled
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$superAdmin->uuid}", ['status' => 'DISABLED'])
            ->assertStatus(409)->assertJson(['error' => ['code' => 'SYSTEM_ROLE_PROTECTED']]);
        // 40/41 code + system_role immutable
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$superAdmin->uuid}", ['code' => 'ROOT'])->assertStatus(422);
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$superAdmin->uuid}", ['system_role' => false])->assertStatus(422);

        // 42 required permission cannot be removed
        $all = Permission::pluck('code')->all();
        $subset = array_slice($all, 0, count($all) - 1); // drop one
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$superAdmin->uuid}/permissions", ['permissions' => $subset])
            ->assertStatus(409)->assertJson(['error' => ['code' => 'SUPER_ADMIN_PERMISSIONS_REQUIRED']]);

        // 43 full catalog remains assignable
        $this->actingAs($admin, 'web')->putJson("/api/v1/roles/{$superAdmin->uuid}/permissions", ['permissions' => $all])->assertOk();
        $this->assertSame(count($all), $superAdmin->fresh()->permissions()->count());
    }
}
