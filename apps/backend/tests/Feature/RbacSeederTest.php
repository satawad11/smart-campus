<?php

namespace Tests\Feature;

use App\Enums\PermissionEffect;
use App\Enums\RoleStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\UserRole;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RbacSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_all_required_roles_are_created_with_expected_attributes(): void
    {
        $expectedRoles = [
            'SUPER_ADMIN' => [
                'name' => 'Super Administrator',
                'system_role' => true,
                'status' => RoleStatus::ACTIVE,
            ],
            'SYSTEM_ADMIN' => [
                'name' => 'System Administrator',
                'system_role' => true,
                'status' => RoleStatus::ACTIVE,
            ],
            'DISCIPLINE_OFFICER' => [
                'name' => 'เจ้าหน้าที่งานปกครอง',
                'system_role' => true,
                'status' => RoleStatus::ACTIVE,
            ],
            'SECURITY_OFFICER' => [
                'name' => 'เจ้าหน้าที่รักษาความปลอดภัย',
                'system_role' => true,
                'status' => RoleStatus::ACTIVE,
            ],
            'EXECUTIVE_VIEWER' => [
                'name' => 'ผู้บริหาร',
                'system_role' => true,
                'status' => RoleStatus::ACTIVE,
            ],
        ];

        $this->assertCount(5, Role::all());

        foreach ($expectedRoles as $code => $attributes) {
            $role = Role::where('code', $code)->first();
            $this->assertNotNull($role, "Role with code '{$code}' should exist.");
            $this->assertSame($attributes['name'], $role->name);
            $this->assertSame($attributes['system_role'], $role->system_role);
            $this->assertSame($attributes['status'], $role->status);
            $this->assertNotEmpty($role->uuid);
            $this->assertNotEmpty($role->description);
        }
    }

    public function test_role_codes_are_unique(): void
    {
        $codes = Role::pluck('code')->all();
        $this->assertCount(5, $codes);
        $this->assertSame($codes, array_unique($codes));
    }

    public function test_required_permission_catalog_is_created(): void
    {
        $expectedModules = [
            'dashboard' => ['dashboard.view'],
            'student' => [
                'student.view',
                'student.create',
                'student.update',
                'student.status.change',
                'student.import',
                'student.export',
                'student.archive',
                'student.sync',
            ],
            'academic' => [
                'academic.view',
                'academic.manage',
            ],
            'face' => [
                'face.view',
                'face.enroll',
                'face.revoke',
                'face.reenroll',
                'face.test',
                'face.model.view',
                'face.model.manage',
            ],
            'camera' => [
                'camera.view',
                'camera.create',
                'camera.update',
                'camera.enable',
                'camera.live',
                'camera.test',
            ],
            'ai_node' => [
                'ai_node.view',
                'ai_node.manage',
            ],
            'monitor' => ['monitor.view'],
            'event' => [
                'event.view',
                'event.review',
                'event.note',
            ],
            'policy' => [
                'policy.view',
                'policy.manage',
            ],
            'report' => [
                'report.view',
                'report.export',
                'report.executive',
                'report.system',
            ],
            'user' => [
                'user.view',
                'user.create',
                'user.update',
                'user.disable',
            ],
            'role' => [
                'role.view',
                'role.manage',
            ],
            'audit' => ['audit.view'],
            'system' => [
                'system.view',
                'system.manage',
                'system.update', // ACR-OPS-01
                'integration.manage', // ACR-INT-02
            ],
            // Module 09 people (PPL-002, OWNER-DECISIONS-2026-11-16).
            'staff' => ['staff.view', 'staff.create', 'staff.update', 'staff.status.change', 'staff.import'],
            'guardian' => ['guardian.view', 'guardian.manage'],
            'visitor' => ['visitor.view', 'visitor.manage', 'visit.view', 'visit.manage'],
            'vehicle' => ['vehicle.view', 'vehicle.manage'],
        ];

        $totalExpected = 0;
        foreach ($expectedModules as $module => $permissions) {
            $totalExpected += count($permissions);
            foreach ($permissions as $code) {
                $permission = Permission::where('code', $code)->first();
                $this->assertNotNull($permission, "Permission '{$code}' should exist.");
                $this->assertSame($module, $permission->module);
                $this->assertNotEmpty($permission->name);
            }
        }

        $this->assertSame(60, $totalExpected);
        $this->assertCount(60, Permission::all());
    }

    public function test_permission_codes_are_unique(): void
    {
        $codes = Permission::pluck('code')->all();
        $this->assertCount(60, $codes);
        $this->assertSame($codes, array_unique($codes));
    }

    public function test_super_admin_receives_all_current_approved_v1_permissions(): void
    {
        $superAdmin = Role::where('code', 'SUPER_ADMIN')->firstOrFail();
        $permissions = $superAdmin->permissions->pluck('code')->all();

        $this->assertCount(60, $permissions);
        $allPermissions = Permission::pluck('code')->all();
        $this->assertEqualsCanonicalizing($allPermissions, $permissions);
    }

    public function test_system_admin_receives_intended_administrative_permissions(): void
    {
        $systemAdmin = Role::where('code', 'SYSTEM_ADMIN')->firstOrFail();
        $permissions = $systemAdmin->permissions->pluck('code')->all();

        // Everything except the SUPER_ADMIN-only system.update (ACR-OPS-01)
        // and integration.manage (ACR-INT-02).
        $this->assertCount(58, $permissions);
        $allPermissions = Permission::whereNotIn('code', ['system.update', 'integration.manage'])->pluck('code')->all();
        $this->assertEqualsCanonicalizing($allPermissions, $permissions);
        $this->assertNotContains('system.update', $permissions);
        $this->assertNotContains('integration.manage', $permissions);
    }

    public function test_discipline_officer_receives_expected_and_no_prohibited_permissions(): void
    {
        $officer = Role::where('code', 'DISCIPLINE_OFFICER')->firstOrFail();
        $permissions = $officer->permissions->pluck('code')->all();

        $expected = [
            'dashboard.view',
            'monitor.view',
            'student.view',
            'student.update',
            'student.status.change',
            'face.view',
            'face.enroll',
            'event.view',
            'event.review',
            'event.note',
            'report.view',
            'report.export',
            'academic.view',
            'staff.view',
            'guardian.view',
            'visit.view',
        ];

        $this->assertCount(count($expected), $permissions);
        $this->assertEqualsCanonicalizing($expected, $permissions);

        // Explicitly assert prohibited permissions are not granted
        $prohibited = [
            'user.view',
            'user.create',
            'user.update',
            'user.disable',
            'role.view',
            'role.manage',
            'system.view',
            'system.manage',
            'audit.view',
            'policy.manage',
            'face.model.manage',
            'face.test',
            'camera.create',
            'camera.update',
            'camera.enable',
            'camera.test',
            'ai_node.view',
            'ai_node.manage',
            'student.archive',
        ];

        foreach ($prohibited as $forbiddenPermission) {
            $this->assertNotContains(
                $forbiddenPermission,
                $permissions,
                "DISCIPLINE_OFFICER must not have prohibited permission '{$forbiddenPermission}'"
            );
        }
    }

    public function test_security_officer_receives_expected_limited_permissions(): void
    {
        $officer = Role::where('code', 'SECURITY_OFFICER')->firstOrFail();
        $permissions = $officer->permissions->pluck('code')->all();

        $expected = [
            'dashboard.view',
            'monitor.view',
            'student.view',
            'camera.view',
            'camera.live',
            'event.view',
            'staff.view',
            'guardian.view',
            'visitor.view',
            'visitor.manage',
            'visit.view',
            'visit.manage',
            'vehicle.view',
        ];

        $this->assertCount(count($expected), $permissions);
        $this->assertEqualsCanonicalizing($expected, $permissions);

        // Prohibited checks
        $prohibited = [
            'user.view',
            'user.create',
            'user.update',
            'user.disable',
            'role.view',
            'role.manage',
            'system.view',
            'system.manage',
            'student.status.change',
            'student.create',
            'student.update',
            'student.import',
            'student.export',
            'student.archive',
            'face.test',
            'face.model.manage',
            'face.enroll',
            'face.revoke',
            'face.reenroll',
            'policy.manage',
            'camera.create',
            'camera.update',
            'camera.enable',
            'camera.test',
            'ai_node.manage',
            'vehicle.manage',
        ];

        foreach ($prohibited as $forbiddenPermission) {
            $this->assertNotContains(
                $forbiddenPermission,
                $permissions,
                "SECURITY_OFFICER must not have prohibited permission '{$forbiddenPermission}'"
            );
        }
    }

    public function test_executive_viewer_remains_read_oriented(): void
    {
        $viewer = Role::where('code', 'EXECUTIVE_VIEWER')->firstOrFail();
        $permissions = $viewer->permissions->pluck('code')->all();

        $expected = [
            'dashboard.view',
            'report.view',
            'report.executive',
        ];

        $this->assertCount(count($expected), $permissions);
        $this->assertEqualsCanonicalizing($expected, $permissions);

        // Prohibited checks
        $prohibited = [
            'student.update',
            'student.status.change',
            'face.view',
            'face.enroll',
            'face.revoke',
            'face.model.manage',
            'face.test',
            'event.review',
            'camera.view',
            'camera.create',
            'camera.update',
            'user.view',
            'user.create',
            'role.view',
            'role.manage',
            'system.manage',
        ];

        foreach ($prohibited as $forbiddenPermission) {
            $this->assertNotContains(
                $forbiddenPermission,
                $permissions,
                "EXECUTIVE_VIEWER must not have prohibited permission '{$forbiddenPermission}'"
            );
        }
    }

    public function test_running_seeders_multiple_times_is_idempotent(): void
    {
        $initialRoleCount = Role::count();
        $initialPermCount = Permission::count();
        $initialPivotCount = DB::table('role_permissions')->count();
        $initialRoleUuids = Role::pluck('uuid', 'code')->all();

        $this->assertSame(5, $initialRoleCount);
        $this->assertSame(60, $initialPermCount);
        $this->assertSame(60 + 58 + 16 + 13 + 3, $initialPivotCount); // 150 assignments (SUPER_ADMIN-only system.update, integration.manage)

        // Run seeders again
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($initialRoleCount, Role::count(), 'Role count must not increase after second seeding.');
        $this->assertSame($initialPermCount, Permission::count(), 'Permission count must not increase after second seeding.');
        $this->assertSame($initialPivotCount, DB::table('role_permissions')->count(), 'Role permissions pivot count must not increase.');

        // UUID stability check
        $secondRoleUuids = Role::pluck('uuid', 'code')->all();
        $this->assertSame($initialRoleUuids, $secondRoleUuids, 'Role UUIDs must remain stable after second seeding.');

        // Run individual seeders directly
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame($initialRoleCount, Role::count());
        $this->assertSame($initialPermCount, Permission::count());
        $this->assertSame($initialPivotCount, DB::table('role_permissions')->count());

        $thirdRoleUuids = Role::pluck('uuid', 'code')->all();
        $this->assertSame($initialRoleUuids, $thirdRoleUuids, 'Role UUIDs must remain stable after third seeding.');
    }

    public function test_user_specific_assignment_tables_are_not_modified_by_seeders(): void
    {
        $user = User::factory()->create();
        $role = Role::where('code', 'SECURITY_OFFICER')->firstOrFail();
        $perm = Permission::where('code', 'dashboard.view')->firstOrFail();

        $userRole = UserRole::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'valid_from' => now(),
            'valid_until' => now()->addYear(),
        ]);

        $userPerm = UserPermission::create([
            'user_id' => $user->id,
            'permission_id' => $perm->id,
            'effect' => PermissionEffect::ALLOW,
        ]);

        $userRoleCountBefore = UserRole::count();
        $userPermCountBefore = UserPermission::count();

        // Run seeders
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($userRoleCountBefore, UserRole::count());
        $this->assertSame($userPermCountBefore, UserPermission::count());

        $this->assertDatabaseHas('user_roles', [
            'id' => $userRole->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $user->id,
            'permission_id' => $perm->id,
            'effect' => PermissionEffect::ALLOW->value,
        ]);
    }

    public function test_future_disabled_module_permissions_are_not_seeded(): void
    {
        // visitor.* left this list when the owner approved Module 09 (OWNER-DECISIONS-2026-11-16, PPL-002).
        // The other phases are approved too, but have no permissions until their own module is built.
        // vehicle.* left it with ACR-VEH-01 (VEH-004); alpr.* gets its own permissions in VEH-012.
        $disallowedPrefixes = ['door.', 'alpr.', 'ai_assistant.'];

        foreach ($disallowedPrefixes as $prefix) {
            $matching = Permission::where('code', 'like', "{$prefix}%")->get();
            $this->assertCount(
                0,
                $matching,
                "Disallowed future module permission with prefix '{$prefix}' was found in database."
            );
        }
    }

    public function test_seeder_does_not_overwrite_runtime_permission_modifications(): void
    {
        $systemAdmin = Role::where('code', 'SYSTEM_ADMIN')->firstOrFail();
        $auditView = Permission::where('code', 'audit.view')->firstOrFail();

        // Administrator modifies SYSTEM_ADMIN permissions at runtime: detach audit.view
        $systemAdmin->permissions()->detach($auditView->id);
        $this->assertFalse($systemAdmin->fresh()->permissions()->where('code', 'audit.view')->exists());
        $countBefore = $systemAdmin->fresh()->permissions()->count();

        // Run db:seed again
        $this->seed(DatabaseSeeder::class);

        // Verification: runtime change survived, audit.view was NOT restored
        $systemAdmin->refresh();
        $this->assertSame($countBefore, $systemAdmin->permissions()->count());
        $this->assertFalse($systemAdmin->permissions()->where('code', 'audit.view')->exists());
    }

    public function test_seeder_does_not_restore_intentionally_removed_permissions(): void
    {
        $disciplineOfficer = Role::where('code', 'DISCIPLINE_OFFICER')->firstOrFail();
        $studentUpdate = Permission::where('code', 'student.update')->firstOrFail();

        // Administrator removes 1 permission from DISCIPLINE_OFFICER at runtime
        $disciplineOfficer->permissions()->detach($studentUpdate->id);
        $this->assertFalse($disciplineOfficer->fresh()->permissions()->where('code', 'student.update')->exists());

        // Run RolePermissionSeeder directly
        $this->seed(RolePermissionSeeder::class);

        // Verification: student.update was NOT restored
        $disciplineOfficer->refresh();
        $this->assertFalse($disciplineOfficer->permissions()->where('code', 'student.update')->exists());
        $this->assertCount(15, $disciplineOfficer->permissions); // 16 seeded (incl. Module 09) minus the removed one
    }

    public function test_seeder_preserves_intentionally_emptied_role_matrix(): void
    {
        $executiveViewer = Role::where('code', 'EXECUTIVE_VIEWER')->firstOrFail();

        // Administrator empties all permissions from EXECUTIVE_VIEWER at runtime
        $executiveViewer->permissions()->detach();
        $this->assertCount(0, $executiveViewer->fresh()->permissions);

        // Run db:seed again
        $this->seed(DatabaseSeeder::class);

        // Verification: Role remains empty and was NOT re-seeded/restored
        $executiveViewer->refresh();
        $this->assertCount(0, $executiveViewer->permissions);
    }

    public function test_seeder_preserves_custom_role_permissions(): void
    {
        $customRole = Role::create([
            'name' => 'Custom Auditor',
            'code' => 'CUSTOM_AUDITOR',
            'system_role' => false,
            'status' => RoleStatus::ACTIVE,
            'description' => 'Custom role created at runtime',
        ]);

        $auditView = Permission::where('code', 'audit.view')->firstOrFail();
        $customRole->permissions()->attach([$auditView->id]);

        $this->assertCount(1, $customRole->fresh()->permissions);

        // Run db:seed again
        $this->seed(DatabaseSeeder::class);

        // Verification: Custom role and its permission assignment remain untouched
        $customRole->refresh();
        $this->assertCount(1, $customRole->permissions);
        $this->assertTrue($customRole->permissions()->where('code', 'audit.view')->exists());
    }
}
