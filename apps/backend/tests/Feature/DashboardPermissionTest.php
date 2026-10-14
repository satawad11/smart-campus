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
use Tests\TestCase;

/**
 * Decision A-14 (2026-10-14): the dashboard (every role's landing page)
 * accepts dashboard.view as well as report.view, with the same data scope.
 * Uses the real seeded role matrix (unchanged).
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
}
