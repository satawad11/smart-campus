<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Decision A-14 (2026-10-14): the dashboard (every role's landing page)
 * accepts dashboard.view as well as report.view, with the same data scope.
 * Uses the real seeded role matrix (unchanged).
 *
 * AUDIT-A14 (owner decision 2026-11-20): SECURITY_OFFICER holds dashboard.view
 * and nothing wider for reporting/system; existing installs get the grant from
 * migration 2026_11_20_700001.
 */
class DashboardPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);
    }

    private function userWithRole(string $role, ?int $siteId = null): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        UserRole::create(['user_id' => $user->id, 'role_id' => Role::where('code', $role)->value('id'), 'site_id' => $siteId]);

        return $user;
    }

    public function test_security_officer_can_open_the_dashboard(): void
    {
        $officer = $this->userWithRole('SECURITY_OFFICER');

        foreach (['summary', 'events-by-hour', 'events-by-decision', 'events-by-camera', 'events-by-department'] as $endpoint) {
            $this->actingAs($officer, 'web')->getJson("/api/v1/dashboard/{$endpoint}")->assertOk();
        }
    }

    public function test_security_officer_still_cannot_open_reports_or_history(): void
    {
        $officer = $this->userWithRole('SECURITY_OFFICER');

        $this->actingAs($officer, 'web')->getJson('/api/v1/reports/daily')->assertStatus(403);
        $this->actingAs($officer, 'web')->getJson('/api/v1/history/search')->assertStatus(403);
    }

    public function test_site_scoped_officer_dashboard_stays_within_scope(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $officer = $this->userWithRole('SECURITY_OFFICER', $siteA->id);

        $this->actingAs($officer, 'web')->getJson('/api/v1/dashboard/summary')->assertOk();
        $this->actingAs($officer, 'web')->getJson("/api/v1/dashboard/summary?site_uuid={$siteB->uuid}")->assertStatus(404);
    }

    public function test_user_without_either_permission_is_refused(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);

        $this->actingAs($user, 'web')->getJson('/api/v1/dashboard/summary')->assertStatus(403);
    }

    private function securityOfficerCodes(): array
    {
        return Role::where('code', 'SECURITY_OFFICER')->firstOrFail()->permissions()->pluck('code')->all();
    }

    public function test_seeded_security_officer_holds_dashboard_view_but_nothing_wider(): void
    {
        $codes = $this->securityOfficerCodes();

        $this->assertContains('dashboard.view', $codes);
        foreach (['report.view', 'report.export', 'report.executive', 'event.review'] as $denied) {
            $this->assertNotContains($denied, $codes);
        }
        $this->assertSame([], array_values(array_filter($codes, fn (string $c) => str_starts_with($c, 'system.'))));
    }

    public function test_migration_restores_dashboard_view_on_an_existing_matrix_without_touching_other_grants(): void
    {
        $roleId = Role::where('code', 'SECURITY_OFFICER')->value('id');
        $dashboardId = DB::table('permissions')->where('code', 'dashboard.view')->value('id');

        // Simulate an existing install whose officer role lacks the grant.
        DB::table('role_permissions')->where(['role_id' => $roleId, 'permission_id' => $dashboardId])->delete();
        $before = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($r) => (array) $r)->all();

        $migration = require database_path('migrations/2026_11_20_700001_grant_security_officer_dashboard_view.php');
        $migration->up();
        $migration->up(); // idempotent

        $after = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->map(fn ($r) => (array) $r)->all();
        $added = array_values(array_filter($after, fn (array $r) => ! in_array($r, $before, true)));

        $this->assertSame([['role_id' => $roleId, 'permission_id' => $dashboardId]], $added);
        $this->assertCount(count($before) + 1, $after);
        $this->assertContains('dashboard.view', $this->securityOfficerCodes());

        $officer = $this->userWithRole('SECURITY_OFFICER');
        $this->actingAs($officer, 'web')->getJson('/api/v1/dashboard/summary')->assertOk();
    }

    public function test_migration_does_nothing_before_the_matrix_is_seeded(): void
    {
        DB::table('role_permissions')->delete();

        $migration = require database_path('migrations/2026_11_20_700001_grant_security_officer_dashboard_view.php');
        $migration->up();

        $this->assertSame(0, DB::table('role_permissions')->count());
    }
}
