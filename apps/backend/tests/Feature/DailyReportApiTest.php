<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Camera;
use App\Models\EventDailySummary;
use App\Models\EventHourlySummary;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RPT-007 — daily report (docs/10-module-06-reporting.md "Reports",
 * docs/02-api-contract.md §16 GET /reports/daily). Combines the same
 * totals/by-decision/by-camera/by-department sections the dashboard shows
 * (RPT-005/006) plus a full by-hour breakdown, for exactly one day.
 */
class DailyReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'RPT_'.uniqid(), 'name' => 'Reporter', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/reports/daily')->assertStatus(401);
    }

    public function test_requires_report_view_permission(): void
    {
        $user = $this->userWith([]);
        $this->actingAs($user, 'web')->getJson('/api/v1/reports/daily')->assertStatus(403);
    }

    public function test_defaults_to_today_and_combines_totals_and_by_hour(): void
    {
        $user = $this->userWith(['report.view']);
        $today = now()->startOfDay();

        EventDailySummary::factory()->create(['summary_date' => $today->toDateString(), 'decision' => 'ALLOW', 'event_count' => 10, 'unique_person_count' => 8, 'event_type' => 'FACE_RECOGNIZED']);
        EventDailySummary::factory()->create(['summary_date' => $today->toDateString(), 'decision' => 'DENY', 'event_count' => 2, 'unique_person_count' => 0, 'event_type' => 'LIVENESS_FAILED']);
        EventHourlySummary::factory()->create(['hour_bucket' => $today->copy()->addHours(9), 'event_count' => 5]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/reports/daily');

        $res->assertOk();
        $res->assertJsonPath('data.from', $today->toDateString());
        $res->assertJsonPath('data.to', $today->toDateString());
        $res->assertJsonPath('data.totals.detected', 12);
        $res->assertJsonPath('data.totals.allow', 10);
        $res->assertJsonPath('data.totals.deny', 2);
        $res->assertJsonPath('data.totals.unique_persons_recognized', 8);
        $res->assertJsonCount(24, 'data.by_hour');
        $res->assertJsonPath('data.by_hour.9.hour', '09:00');
        $res->assertJsonPath('data.by_hour.9.count', 5);
    }

    public function test_explicit_date_parameter_is_used_instead_of_today(): void
    {
        $user = $this->userWith(['report.view']);
        $pastDate = now()->subDays(3)->toDateString();
        EventDailySummary::factory()->create(['summary_date' => $pastDate, 'event_count' => 42]);
        EventDailySummary::factory()->create(['summary_date' => now()->toDateString(), 'event_count' => 999]);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/reports/daily?date={$pastDate}");

        $res->assertOk();
        $res->assertJsonPath('data.totals.detected', 42);
    }

    public function test_by_camera_and_by_department_sections_are_present(): void
    {
        $user = $this->userWith(['report.view']);
        $camera = Camera::factory()->create(['name' => 'Main Gate']);
        $today = now()->toDateString();
        EventDailySummary::factory()->create(['summary_date' => $today, 'camera_id' => $camera->id, 'event_count' => 6]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/reports/daily');

        $res->assertOk();
        $byCamera = collect($res->json('data.by_camera'))->firstWhere('camera_uuid', $camera->uuid);
        $this->assertSame('Main Gate', $byCamera['camera_name']);
        $this->assertSame(6, $byCamera['count']);
    }

    public function test_site_filter_scopes_the_whole_report(): void
    {
        $user = $this->userWith(['report.view']);
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $today = now()->toDateString();

        EventDailySummary::factory()->create(['site_id' => $site->id, 'summary_date' => $today, 'event_count' => 4]);
        EventDailySummary::factory()->create(['site_id' => $otherSite->id, 'summary_date' => $today, 'event_count' => 40]);
        EventHourlySummary::factory()->create(['site_id' => $site->id, 'hour_bucket' => now()->startOfDay()->addHours(6), 'event_count' => 4]);
        EventHourlySummary::factory()->create(['site_id' => $otherSite->id, 'hour_bucket' => now()->startOfDay()->addHours(6), 'event_count' => 40]);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/reports/daily?site_uuid={$site->uuid}");

        $res->assertOk();
        $res->assertJsonPath('data.totals.detected', 4);
        $res->assertJsonPath('data.by_hour.6.count', 4);
    }
}
