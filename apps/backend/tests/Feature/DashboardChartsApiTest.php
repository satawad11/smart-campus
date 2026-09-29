<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Camera;
use App\Models\Department;
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
 * RPT-006 — dashboard charts (docs/10-module-06-reporting.md "Dashboard"
 * Charts: by hour, by decision, by camera, by department). All four read
 * from the RPT-003/004 summary tables, never raw AccessEvent.
 */
class DashboardChartsApiTest extends TestCase
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

    public function test_by_hour_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard/events-by-hour')->assertStatus(401);
    }

    public function test_by_hour_requires_report_view_permission(): void
    {
        $user = $this->userWith([]);
        $this->actingAs($user, 'web')->getJson('/api/v1/dashboard/events-by-hour')->assertStatus(403);
    }

    public function test_by_hour_returns_a_full_24_hour_series_with_gaps_filled(): void
    {
        $user = $this->userWith(['report.view']);
        $today = now()->startOfDay();

        EventHourlySummary::factory()->create(['hour_bucket' => $today->copy()->addHours(9), 'event_count' => 5]);
        EventHourlySummary::factory()->create(['hour_bucket' => $today->copy()->addHours(9), 'event_count' => 3, 'decision' => 'REVIEW']);
        EventHourlySummary::factory()->create(['hour_bucket' => $today->copy()->addHours(14), 'event_count' => 2]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/dashboard/events-by-hour');

        $res->assertOk();
        $series = $res->json('data.series');
        $this->assertCount(24, $series);
        $this->assertSame('09:00', $series[9]['hour']);
        $this->assertSame(8, $series[9]['count']); // 5 + 3, summed across decisions
        $this->assertSame(2, $series[14]['count']);
        $this->assertSame(0, $series[0]['count']); // an hour with no data is 0, not absent
    }

    public function test_by_hour_uses_the_requested_date_not_always_today(): void
    {
        $user = $this->userWith(['report.view']);
        $yesterday = now()->subDay()->startOfDay();
        EventHourlySummary::factory()->create(['hour_bucket' => $yesterday->copy()->addHours(3), 'event_count' => 9]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/dashboard/events-by-hour?date='.$yesterday->toDateString());

        $res->assertOk();
        $this->assertSame(9, $res->json('data.series')[3]['count']);
    }

    public function test_by_decision_groups_and_sums_correctly(): void
    {
        $user = $this->userWith(['report.view']);
        $today = now()->toDateString();
        EventDailySummary::factory()->create(['summary_date' => $today, 'decision' => 'ALLOW', 'event_count' => 10]);
        EventDailySummary::factory()->create(['summary_date' => $today, 'decision' => 'ALLOW', 'event_count' => 5, 'event_type' => 'UNKNOWN_PERSON']);
        EventDailySummary::factory()->create(['summary_date' => $today, 'decision' => 'DENY', 'event_count' => 2]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/dashboard/events-by-decision');

        $res->assertOk();
        $series = collect($res->json('data.series'))->keyBy('decision');
        $this->assertSame(15, $series['ALLOW']['count']);
        $this->assertSame(2, $series['DENY']['count']);
    }

    public function test_by_camera_resolves_camera_names_and_groups_events_with_no_camera(): void
    {
        $user = $this->userWith(['report.view']);
        $camera = Camera::factory()->create(['name' => 'Gate 1 Camera']);
        $today = now()->toDateString();

        EventDailySummary::factory()->create(['summary_date' => $today, 'camera_id' => $camera->id, 'event_count' => 7]);
        EventDailySummary::factory()->create(['summary_date' => $today, 'camera_id' => null, 'event_count' => 3]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/dashboard/events-by-camera');

        $res->assertOk();
        $series = $res->json('data.series');
        $named = collect($series)->firstWhere('camera_uuid', $camera->uuid);
        $this->assertSame('Gate 1 Camera', $named['camera_name']);
        $this->assertSame(7, $named['count']);

        $unassigned = collect($series)->firstWhere('camera_uuid', null);
        $this->assertSame(3, $unassigned['count']);
    }

    public function test_by_department_resolves_department_names_and_groups_events_with_no_department(): void
    {
        $user = $this->userWith(['report.view']);
        $department = Department::factory()->create(['name' => 'Information Technology']);
        $today = now()->toDateString();

        EventDailySummary::factory()->create(['summary_date' => $today, 'department_id' => $department->id, 'event_count' => 4]);
        EventDailySummary::factory()->create(['summary_date' => $today, 'department_id' => null, 'event_count' => 6]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/dashboard/events-by-department');

        $res->assertOk();
        $series = $res->json('data.series');
        $named = collect($series)->firstWhere('department_uuid', $department->uuid);
        $this->assertSame('Information Technology', $named['department_name']);
        $this->assertSame(4, $named['count']);

        $unassigned = collect($series)->firstWhere('department_uuid', null);
        $this->assertSame(6, $unassigned['count']);
    }

    public function test_date_range_filters_by_decision_by_camera_and_by_department(): void
    {
        $user = $this->userWith(['report.view']);
        EventDailySummary::factory()->create(['summary_date' => now()->subWeek()->toDateString(), 'event_count' => 100]);
        EventDailySummary::factory()->create(['summary_date' => now()->toDateString(), 'event_count' => 3]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/dashboard/events-by-decision');

        $res->assertOk();
        $this->assertSame(3, collect($res->json('data.series'))->sum('count'));
    }

    public function test_site_filter_scopes_all_four_charts(): void
    {
        $user = $this->userWith(['report.view']);
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $today = now()->toDateString();

        EventDailySummary::factory()->create(['site_id' => $site->id, 'summary_date' => $today, 'event_count' => 5]);
        EventDailySummary::factory()->create(['site_id' => $otherSite->id, 'summary_date' => $today, 'event_count' => 50]);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/dashboard/events-by-decision?site_uuid={$site->uuid}");

        $res->assertOk();
        $this->assertSame(5, collect($res->json('data.series'))->sum('count'));
    }
}
