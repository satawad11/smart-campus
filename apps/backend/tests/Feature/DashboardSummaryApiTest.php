<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AiNode;
use App\Models\AiNodeHealthLog;
use App\Models\Camera;
use App\Models\CameraHealthLog;
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
 * RPT-005 — dashboard summary (docs/10-module-06-reporting.md
 * "Dashboard"). Reads event metrics from EventDailySummary only — never
 * scans raw AccessEvent — and camera/AI-node online/total from each
 * device's latestHealth relation.
 */
class DashboardSummaryApiTest extends TestCase
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
        $this->getJson('/api/v1/dashboard/summary')->assertStatus(401);
    }

    public function test_requires_report_view_permission(): void
    {
        $user = $this->userWith([]);
        $this->actingAs($user, 'web')->getJson('/api/v1/dashboard/summary')->assertStatus(403);
    }

    public function test_event_metrics_are_read_from_the_daily_summary_not_raw_events(): void
    {
        $user = $this->userWith(['report.view']);
        $today = now()->toDateString();

        EventDailySummary::factory()->create([
            'summary_date' => $today, 'event_type' => 'FACE_RECOGNIZED', 'decision' => 'ALLOW',
            'event_count' => 10, 'unique_person_count' => 8,
        ]);
        EventDailySummary::factory()->create([
            'summary_date' => $today, 'event_type' => 'FACE_RECOGNIZED', 'decision' => 'REVIEW',
            'event_count' => 3, 'unique_person_count' => 3,
        ]);
        EventDailySummary::factory()->create([
            'summary_date' => $today, 'event_type' => 'UNKNOWN_PERSON', 'decision' => 'UNKNOWN',
            'event_count' => 5, 'unique_person_count' => 0,
        ]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/dashboard/summary');

        $res->assertOk();
        $res->assertJsonPath('data.events.detected', 18);
        $res->assertJsonPath('data.events.recognized', 13);
        $res->assertJsonPath('data.events.allow', 10);
        $res->assertJsonPath('data.events.review', 3);
        $res->assertJsonPath('data.events.unknown', 5);
        $res->assertJsonPath('data.events.deny', 0);
        $res->assertJsonPath('data.unique_persons_recognized', 11);
    }

    public function test_only_the_requested_dates_summary_rows_are_counted(): void
    {
        $user = $this->userWith(['report.view']);
        EventDailySummary::factory()->create(['summary_date' => now()->subDay()->toDateString(), 'event_count' => 99]);
        EventDailySummary::factory()->create(['summary_date' => now()->toDateString(), 'event_count' => 4]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/dashboard/summary');

        $res->assertOk();
        $res->assertJsonPath('data.events.detected', 4);
    }

    public function test_site_filter_scopes_both_events_and_device_counts(): void
    {
        $user = $this->userWith(['report.view']);
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $today = now()->toDateString();

        EventDailySummary::factory()->create(['site_id' => $site->id, 'summary_date' => $today, 'event_count' => 7]);
        EventDailySummary::factory()->create(['site_id' => $otherSite->id, 'summary_date' => $today, 'event_count' => 50]);

        $camera = Camera::factory()->create(['site_id' => $site->id, 'status' => 'ACTIVE']);
        Camera::factory()->create(['site_id' => $otherSite->id, 'status' => 'ACTIVE']);
        CameraHealthLog::factory()->for($camera)->create(['status' => 'ONLINE', 'recorded_at' => now()]);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/dashboard/summary?site_uuid={$site->uuid}");

        $res->assertOk();
        $res->assertJsonPath('data.events.detected', 7);
        $res->assertJsonPath('data.cameras.total', 1);
        $res->assertJsonPath('data.cameras.online', 1);
    }

    public function test_camera_online_count_reflects_only_the_latest_health_log(): void
    {
        $user = $this->userWith(['report.view']);
        $camera = Camera::factory()->create(['status' => 'ACTIVE']);
        CameraHealthLog::factory()->for($camera)->create(['status' => 'ONLINE', 'recorded_at' => now()->subMinutes(10)]);
        CameraHealthLog::factory()->for($camera)->create(['status' => 'OFFLINE', 'recorded_at' => now()]); // most recent

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/dashboard/summary');

        $res->assertOk();
        $res->assertJsonPath('data.cameras.total', 1);
        $res->assertJsonPath('data.cameras.online', 0);
    }

    public function test_a_camera_with_no_health_log_at_all_counts_toward_total_but_not_online(): void
    {
        $user = $this->userWith(['report.view']);
        Camera::factory()->create(['status' => 'ACTIVE']);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/dashboard/summary');

        $res->assertOk();
        $res->assertJsonPath('data.cameras.total', 1);
        $res->assertJsonPath('data.cameras.online', 0);
    }

    public function test_inactive_cameras_are_excluded_from_the_total(): void
    {
        $user = $this->userWith(['report.view']);
        Camera::factory()->create(['status' => 'ACTIVE']);
        Camera::factory()->create(['status' => 'INACTIVE']);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/dashboard/summary');

        $res->assertOk();
        $res->assertJsonPath('data.cameras.total', 1);
    }

    public function test_ai_node_online_total_uses_the_same_latest_health_pattern(): void
    {
        $user = $this->userWith(['report.view']);
        $node = AiNode::factory()->create(['status' => 'ACTIVE']);
        AiNodeHealthLog::factory()->for($node)->create(['status' => 'ONLINE', 'recorded_at' => now()]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/dashboard/summary');

        $res->assertOk();
        $res->assertJsonPath('data.ai_nodes.total', 1);
        $res->assertJsonPath('data.ai_nodes.online', 1);
    }

    public function test_explicit_date_parameter_overrides_today(): void
    {
        $user = $this->userWith(['report.view']);
        $pastDate = now()->subDays(5)->toDateString();
        EventDailySummary::factory()->create(['summary_date' => $pastDate, 'event_count' => 42]);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/dashboard/summary?date={$pastDate}");

        $res->assertOk();
        $res->assertJsonPath('data.events.detected', 42);
        $res->assertJsonPath('data.date', $pastDate);
    }
}
