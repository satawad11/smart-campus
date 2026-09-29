<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\EventDailySummary;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RPT-008..RPT-011 — periodic and custom reports
 * (weekly, monthly, yearly/academic, custom).
 */
class PeriodicReportsApiTest extends TestCase
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

    public function test_weekly_report_aggregates_seven_day_range_with_daily_breakdown(): void
    {
        $user = $this->userWith(['report.view']);
        $now = now();
        $startOfWeek = $now->copy()->startOfWeek();

        EventDailySummary::factory()->create([
            'summary_date' => $startOfWeek->toDateString(),
            'decision' => 'ALLOW',
            'event_count' => 15,
            'unique_person_count' => 10,
            'event_type' => 'FACE_RECOGNIZED',
        ]);
        EventDailySummary::factory()->create([
            'summary_date' => $startOfWeek->copy()->addDays(2)->toDateString(),
            'decision' => 'DENY',
            'event_count' => 5,
            'unique_person_count' => 0,
            'event_type' => 'LIVENESS_FAILED',
        ]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/reports/weekly');

        $res->assertOk();
        $res->assertJsonPath('data.from', $startOfWeek->toDateString());
        $res->assertJsonPath('data.to', $now->copy()->endOfWeek()->toDateString());
        $res->assertJsonPath('data.totals.detected', 20);
        $res->assertJsonPath('data.totals.allow', 15);
        $res->assertJsonPath('data.totals.deny', 5);
        $res->assertJsonCount(7, 'data.by_day');
    }

    public function test_monthly_report_aggregates_calendar_month(): void
    {
        $user = $this->userWith(['report.view']);
        $startOfMonth = now()->copy()->startOfMonth();

        EventDailySummary::factory()->create([
            'summary_date' => $startOfMonth->toDateString(),
            'decision' => 'ALLOW',
            'event_count' => 50,
            'event_type' => 'FACE_RECOGNIZED',
        ]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/reports/monthly');

        $res->assertOk();
        $res->assertJsonPath('data.from', $startOfMonth->toDateString());
        $res->assertJsonPath('data.to', now()->copy()->endOfMonth()->toDateString());
        $res->assertJsonPath('data.totals.detected', 50);
        $this->assertNotEmpty($res->json('data.by_day'));
    }

    public function test_yearly_calendar_and_academic_year_reports(): void
    {
        $user = $this->userWith(['report.view']);
        $year = 2026;

        EventDailySummary::factory()->create([
            'summary_date' => '2026-06-15',
            'decision' => 'ALLOW',
            'event_count' => 100,
            'event_type' => 'FACE_RECOGNIZED',
        ]);

        // Calendar year
        $resCal = $this->actingAs($user, 'web')->getJson("/api/v1/reports/yearly?year={$year}&type=calendar");
        $resCal->assertOk();
        $resCal->assertJsonPath('data.from', '2026-01-01');
        $resCal->assertJsonPath('data.to', '2026-12-31');
        $resCal->assertJsonPath('data.calendar_type', 'calendar');
        $resCal->assertJsonPath('data.totals.detected', 100);
        $resCal->assertJsonCount(12, 'data.by_month');

        // Academic year (May 1 2026 - April 30 2027)
        $resAcad = $this->actingAs($user, 'web')->getJson("/api/v1/reports/yearly?year={$year}&type=academic");
        $resAcad->assertOk();
        $resAcad->assertJsonPath('data.from', '2026-05-01');
        $resAcad->assertJsonPath('data.to', '2027-04-30');
        $resAcad->assertJsonPath('data.calendar_type', 'academic');
        $resAcad->assertJsonPath('data.academic_year', 2026);
        $resAcad->assertJsonPath('data.totals.detected', 100);
    }

    public function test_custom_report_with_date_range(): void
    {
        $user = $this->userWith(['report.view']);

        EventDailySummary::factory()->create([
            'summary_date' => '2026-03-10',
            'decision' => 'ALLOW',
            'event_count' => 30,
            'event_type' => 'FACE_RECOGNIZED',
        ]);
        EventDailySummary::factory()->create([
            'summary_date' => '2026-03-25',
            'decision' => 'DENY',
            'event_count' => 10,
            'event_type' => 'UNKNOWN_FACE',
        ]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/reports/custom?from=2026-03-01&to=2026-03-31');

        $res->assertOk();
        $res->assertJsonPath('data.from', '2026-03-01');
        $res->assertJsonPath('data.to', '2026-03-31');
        $res->assertJsonPath('data.totals.detected', 40);
        $res->assertJsonPath('data.totals.allow', 30);
        $res->assertJsonPath('data.totals.deny', 10);
        $res->assertJsonCount(31, 'data.by_day');
    }

    public function test_site_scoping_applies_across_reports(): void
    {
        $user = $this->userWith(['report.view']);
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();

        EventDailySummary::factory()->create([
            'site_id' => $siteA->id,
            'summary_date' => '2026-07-10',
            'event_count' => 25,
        ]);
        EventDailySummary::factory()->create([
            'site_id' => $siteB->id,
            'summary_date' => '2026-07-10',
            'event_count' => 75,
        ]);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/reports/custom?from=2026-07-01&to=2026-07-31&site_uuid={$siteA->uuid}");

        $res->assertOk();
        $res->assertJsonPath('data.totals.detected', 25);
    }
}
