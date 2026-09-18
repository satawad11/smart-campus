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
use App\Support\Authorization\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AUTH-009 GLOBAL/SITE/ZONE scope authorization.
 */
class ScopeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private PermissionService $svc;

    private Permission $perm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(PermissionService::class);
        $this->perm = Permission::create([
            'code' => 'student.view',
            'module' => 'student',
            'name' => 'View students',
        ]);
    }

    private function activeUser(): User
    {
        return User::factory()->create(['status' => UserStatus::ACTIVE]);
    }

    private function roleWithPermission(string $code = 'STUDENT_VIEWER'): Role
    {
        $role = Role::create([
            'code' => $code.'_'.uniqid(),
            'name' => 'Role',
            'system_role' => false,
            'status' => RoleStatus::ACTIVE,
        ]);
        $role->permissions()->attach($this->perm->id);

        return $role;
    }

    private function assign(User $user, Role $role, ?int $siteId, ?int $zoneId, array $validity = []): void
    {
        UserRole::create(array_merge([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'site_id' => $siteId,
            'zone_id' => $zoneId,
        ], $validity));
    }

    // 1-2: GLOBAL assignment covers arbitrary site and zone contexts.
    public function test_global_assignment_covers_arbitrary_site_and_zone(): void
    {
        $user = $this->activeUser();
        $this->assign($user, $this->roleWithPermission(), null, null);

        $this->assertTrue($this->svc->can($user, 'student.view', ScopeContext::global()));
        $this->assertTrue($this->svc->can($user, 'student.view', ScopeContext::site(999)));
        $this->assertTrue($this->svc->can($user, 'student.view', ScopeContext::zone(1234, 999)));
    }

    // 3-4: SITE assignment covers its site, not another.
    public function test_site_assignment_covers_own_site_only(): void
    {
        $user = $this->activeUser();
        $this->assign($user, $this->roleWithPermission(), 10, null);

        $this->assertTrue($this->svc->can($user, 'student.view', ScopeContext::site(10)));
        $this->assertFalse($this->svc->can($user, 'student.view', ScopeContext::site(20)));
    }

    // 5-6: SITE assignment covers zone in same site, not zone in another site.
    public function test_site_assignment_covers_zone_in_same_site(): void
    {
        $user = $this->activeUser();
        $this->assign($user, $this->roleWithPermission(), 10, null);

        $this->assertTrue($this->svc->can($user, 'student.view', ScopeContext::zone(101, 10)));
        $this->assertFalse($this->svc->can($user, 'student.view', ScopeContext::zone(201, 20)));
    }

    // 7-9: ZONE assignment covers exact zone only.
    public function test_zone_assignment_covers_exact_zone_only(): void
    {
        $user = $this->activeUser();
        $this->assign($user, $this->roleWithPermission(), 10, 101);

        $this->assertTrue($this->svc->can($user, 'student.view', ScopeContext::zone(101, 10)));
        $this->assertFalse($this->svc->can($user, 'student.view', ScopeContext::zone(102, 10)));
        // Does not imply whole-site access.
        $this->assertFalse($this->svc->can($user, 'student.view', ScopeContext::site(10)));
    }

    // 10: multiple SITE assignments combine.
    public function test_multiple_site_assignments_combine(): void
    {
        $user = $this->activeUser();
        $this->assign($user, $this->roleWithPermission('A'), 10, null);
        $this->assign($user, $this->roleWithPermission('B'), 20, null);

        $this->assertTrue($this->svc->can($user, 'student.view', ScopeContext::site(10)));
        $this->assertTrue($this->svc->can($user, 'student.view', ScopeContext::site(20)));
        $this->assertFalse($this->svc->can($user, 'student.view', ScopeContext::site(30)));
    }

    // 11: multiple ZONE assignments combine.
    public function test_multiple_zone_assignments_combine(): void
    {
        $user = $this->activeUser();
        $this->assign($user, $this->roleWithPermission('A'), 10, 100);
        $this->assign($user, $this->roleWithPermission('B'), 10, 101);

        $this->assertTrue($this->svc->can($user, 'student.view', ScopeContext::zone(100, 10)));
        $this->assertTrue($this->svc->can($user, 'student.view', ScopeContext::zone(101, 10)));
        $this->assertFalse($this->svc->can($user, 'student.view', ScopeContext::zone(102, 10)));
    }

    // 12: GLOBAL grant dominates scope coverage.
    public function test_global_grant_dominates(): void
    {
        $user = $this->activeUser();
        $this->assign($user, $this->roleWithPermission('SITE'), 10, null);
        $this->assign($user, $this->roleWithPermission('GLOBAL'), null, null);

        $this->assertTrue($this->svc->effectiveScope($user, 'student.view')->isGlobal());
        $this->assertTrue($this->svc->can($user, 'student.view', ScopeContext::site(20)));
    }

    // 13: direct ALLOW grants permission globally.
    public function test_direct_allow_is_global(): void
    {
        $user = $this->activeUser();
        UserPermission::create([
            'user_id' => $user->id,
            'permission_id' => $this->perm->id,
            'effect' => PermissionEffect::ALLOW,
        ]);

        $this->assertTrue($this->svc->effectiveScope($user, 'student.view')->isGlobal());
        $this->assertTrue($this->svc->can($user, 'student.view', ScopeContext::site(777)));
        $this->assertTrue($this->svc->can($user, 'student.view', ScopeContext::global()));
    }

    // 14-15: direct DENY denies globally and overrides GLOBAL role grant.
    public function test_direct_deny_is_global_and_overrides_role(): void
    {
        $user = $this->activeUser();
        $this->assign($user, $this->roleWithPermission(), null, null); // GLOBAL role grant
        UserPermission::create([
            'user_id' => $user->id,
            'permission_id' => $this->perm->id,
            'effect' => PermissionEffect::DENY,
        ]);

        $this->assertTrue($this->svc->effectiveScope($user, 'student.view')->isNone());
        $this->assertFalse($this->svc->can($user, 'student.view', ScopeContext::site(10)));
        $this->assertFalse($this->svc->can($user, 'student.view', ScopeContext::global()));
    }

    // 16-18: validity windows.
    public function test_future_and_expired_assignments_grant_no_scope(): void
    {
        $user = $this->activeUser();
        // Future assignment.
        $this->assign($user, $this->roleWithPermission('FUT'), 10, null, [
            'valid_from' => now()->addDay(),
        ]);
        $this->assertFalse($this->svc->can($user, 'student.view', ScopeContext::site(10)));

        // Expired assignment.
        $user2 = $this->activeUser();
        $this->assign($user2, $this->roleWithPermission('EXP'), 10, null, [
            'valid_until' => now()->subDay(),
        ]);
        $this->assertFalse($this->svc->can($user2, 'student.view', ScopeContext::site(10)));

        // Currently valid assignment.
        $user3 = $this->activeUser();
        $this->assign($user3, $this->roleWithPermission('VAL'), 10, null, [
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDay(),
        ]);
        $this->assertTrue($this->svc->can($user3, 'student.view', ScopeContext::site(10)));
    }

    // 19-21: inactive users receive no scope.
    public function test_inactive_users_receive_no_scope(): void
    {
        foreach ([UserStatus::DISABLED, UserStatus::LOCKED, UserStatus::EXPIRED] as $status) {
            $user = User::factory()->create(['status' => $status]);
            $this->assign($user, $this->roleWithPermission(), null, null);

            $this->assertFalse(
                $this->svc->can($user, 'student.view', ScopeContext::site(10)),
                "status {$status->value} must not be authorized"
            );
            $this->assertTrue($this->svc->effectiveScope($user, 'student.view')->isNone());
        }
    }

    // 22: permission missing -> deny.
    public function test_missing_permission_denies(): void
    {
        $user = $this->activeUser();
        $this->assign($user, $this->roleWithPermission(), null, null);

        $this->assertFalse($this->svc->can($user, 'nonexistent.permission', ScopeContext::global()));
    }

    // 23: permission exists but only outside requested scope -> deny.
    public function test_permission_outside_requested_scope_denies(): void
    {
        $user = $this->activeUser();
        $this->assign($user, $this->roleWithPermission(), 10, null);

        $this->assertFalse($this->svc->can($user, 'student.view', ScopeContext::site(20)));
    }

    // 24: role has scope but lacks permission -> deny.
    public function test_role_with_scope_but_without_permission_denies(): void
    {
        $user = $this->activeUser();
        $role = Role::create([
            'code' => 'NO_PERM',
            'name' => 'No perm',
            'system_role' => false,
            'status' => RoleStatus::ACTIVE,
        ]);
        $this->assign($user, $role, 10, null); // role has no permissions attached

        $this->assertFalse($this->svc->can($user, 'student.view', ScopeContext::site(10)));
    }

    // 25: SUPER_ADMIN gets scope only from its assignment, no name bypass.
    public function test_super_admin_has_no_name_based_bypass(): void
    {
        $user = $this->activeUser();
        $role = Role::create([
            'code' => 'SUPER_ADMIN',
            'name' => 'Super Admin',
            'system_role' => true,
            'status' => RoleStatus::ACTIVE,
        ]);
        $role->permissions()->attach($this->perm->id);
        // Assigned only to SITE 10 (not global).
        $this->assign($user, $role, 10, null);

        $this->assertFalse($this->svc->can($user, 'student.view', ScopeContext::site(20)));
        $this->assertFalse($this->svc->effectiveScope($user, 'student.view')->isGlobal());
        $this->assertTrue($this->svc->can($user, 'student.view', ScopeContext::site(10)));
    }

    // 26: SYSTEM_ADMIN can be deliberately site-scoped.
    public function test_system_admin_can_be_site_scoped(): void
    {
        $user = $this->activeUser();
        $role = Role::create([
            'code' => 'SYSTEM_ADMIN',
            'name' => 'System Admin',
            'system_role' => true,
            'status' => RoleStatus::ACTIVE,
        ]);
        $role->permissions()->attach($this->perm->id);
        $this->assign($user, $role, 10, null);

        $this->assertFalse($this->svc->effectiveScope($user, 'student.view')->isGlobal());
        $this->assertTrue($this->svc->can($user, 'student.view', ScopeContext::site(10)));
    }

    // 29: effective-scope representation.
    public function test_effective_scope_representation(): void
    {
        $user = $this->activeUser();
        $this->assign($user, $this->roleWithPermission('S'), 10, null);
        $this->assign($user, $this->roleWithPermission('Z'), 20, 200);

        $scope = $this->svc->effectiveScope($user, 'student.view');
        $this->assertFalse($scope->isGlobal());
        $this->assertFalse($scope->isNone());
        $this->assertEqualsCanonicalizing([10], $scope->siteIds());
        $this->assertEqualsCanonicalizing([200], $scope->zoneIds());
        $this->assertSame('SCOPED', $scope->toArray()['scope']);

        // accessible-id helpers.
        $this->assertEqualsCanonicalizing([10], $this->svc->accessibleSiteIds($user, 'student.view'));
        $this->assertEqualsCanonicalizing([200], $this->svc->accessibleZoneIds($user, 'student.view'));

        // GLOBAL -> null (all).
        $g = $this->activeUser();
        $this->assign($g, $this->roleWithPermission('G'), null, null);
        $this->assertNull($this->svc->accessibleSiteIds($g, 'student.view'));
    }

    // 30: query-scope helper restricts a generic query without domain coupling.
    public function test_apply_scope_restricts_generic_query(): void
    {
        $user = $this->activeUser();
        $this->assign($user, $this->roleWithPermission('S'), 10, null); // site 10
        $this->assign($user, $this->roleWithPermission('Z'), 20, 205);  // zone 205

        DB::statement('CREATE TEMPORARY TABLE scope_probe (id integer, site_id integer null, zone_id integer null)');
        DB::table('scope_probe')->insert([
            ['id' => 1, 'site_id' => 10, 'zone_id' => null],  // in site 10  -> visible
            ['id' => 2, 'site_id' => 20, 'zone_id' => 205],   // zone 205    -> visible
            ['id' => 3, 'site_id' => 20, 'zone_id' => 999],   // other zone  -> hidden
            ['id' => 4, 'site_id' => 30, 'zone_id' => null],  // other site  -> hidden
        ]);

        $visible = $this->svc
            ->applyScope(DB::table('scope_probe'), $user, 'student.view')
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing([1, 2], $visible);

        // GLOBAL user sees everything.
        $g = $this->activeUser();
        $this->assign($g, $this->roleWithPermission('G'), null, null);
        $allVisible = $this->svc->applyScope(DB::table('scope_probe'), $g, 'student.view')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([1, 2, 3, 4], $allVisible);

        // NONE user sees nothing.
        $none = $this->activeUser();
        $noneVisible = $this->svc->applyScope(DB::table('scope_probe'), $none, 'student.view')->pluck('id')->all();
        $this->assertSame([], $noneVisible);
    }
}
