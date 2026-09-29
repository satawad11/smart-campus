<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\Camera;
use App\Models\EventDailySummary;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Zone;
use App\Services\Report\EventSummaryRefreshService;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * RPT-022: Reporting correctness, timezone boundaries, and scope isolation tests
 * (docs/10-module-06-reporting.md §Accuracy Rules, ACCEPTANCE_CRITERIA.md §Module 06).
 */
class ReportingCorrectnessTimezoneScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(EventTypeSeeder::class);
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

    public function test_distinguishes_total_events_from_unique_persons(): void
    {
        $user = $this->userWith(['report.view']);
        $site = Site::factory()->create();
        $today = now()->toDateString();

        // 100 total events, but only 12 unique persons recognized
        EventDailySummary::factory()->create([
            'site_id' => $site->id,
            'summary_date' => $today,
            'event_type' => 'FACE_RECOGNIZED',
            'decision' => 'ALLOW',
            'event_count' => 100,
            'unique_person_count' => 12,
        ]);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/reports/daily?date={$today}&site_uuid={$site->uuid}");

        $res->assertOk();
        $totals = $res->json('data.totals');
        $this->assertSame(100, $totals['detected']);
        $this->assertSame(100, $totals['recognized']);
        $this->assertSame(12, $totals['unique_persons_recognized']);
        $this->assertNotEquals($totals['detected'], $totals['unique_persons_recognized']);
    }

    public function test_unknown_events_do_not_falsely_count_as_unique_persons(): void
    {
        $user = $this->userWith(['report.view']);
        $site = Site::factory()->create();
        $today = now()->toDateString();

        // 45 unknown face events
        EventDailySummary::factory()->create([
            'site_id' => $site->id,
            'summary_date' => $today,
            'event_type' => 'UNKNOWN_FACE',
            'decision' => 'UNKNOWN',
            'event_count' => 45,
            'unique_person_count' => 0,
        ]);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/reports/unknown?from={$today}&to={$today}&site_uuid={$site->uuid}");

        $res->assertOk();
        $this->assertSame(45, $res->json('data.total_unknowns'));
        // Note explicitly clarifies unknown events are not unique persons
        $this->assertStringContainsString('not necessarily correspond to unique individuals', $res->json('data.note'));

        // Check daily totals
        $dailyRes = $this->actingAs($user, 'web')->getJson("/api/v1/reports/daily?date={$today}&site_uuid={$site->uuid}");
        $dailyRes->assertOk();
        $this->assertSame(45, $dailyRes->json('data.totals.unknown'));
        $this->assertSame(0, $dailyRes->json('data.totals.unique_persons_recognized'));
    }

    public function test_timezone_boundaries_across_midnight(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->create(['site_id' => $site->id]);
        $camera = Camera::factory()->create(['site_id' => $site->id, 'zone_id' => $zone->id]);
        $person = Person::factory()->create();

        // Target dates: Day 1 (2026-08-15) and Day 2 (2026-08-16)
        $day1Start = Carbon::parse('2026-08-15 00:00:00');
        $day2Start = Carbon::parse('2026-08-16 00:00:00');

        // Event 1: Day 1 (23:59)
        $e1 = AccessEvent::factory()->create([
            'site_id' => $site->id,
            'zone_id' => $zone->id,
            'camera_id' => $camera->id,
            'person_id' => $person->id,
            'occurred_at' => $day1Start->copy()->addHours(23)->addMinutes(59),
        ]);
        AccessDecision::factory()->for($e1, 'event')->create(['decision' => 'ALLOW']);

        // Event 2: Day 2 (00:01)
        $e2 = AccessEvent::factory()->create([
            'site_id' => $site->id,
            'zone_id' => $zone->id,
            'camera_id' => $camera->id,
            'person_id' => $person->id,
            'occurred_at' => $day2Start->copy()->addMinutes(1),
        ]);
        AccessDecision::factory()->for($e2, 'event')->create(['decision' => 'ALLOW']);

        // Run refresh service for each day
        $refreshService = app(EventSummaryRefreshService::class);
        $refreshService->refreshDay($day1Start->copy());
        $refreshService->refreshDay($day2Start->copy());

        $day1 = EventDailySummary::whereDate('summary_date', '2026-08-15')->where('site_id', $site->id)->first();
        $day2 = EventDailySummary::whereDate('summary_date', '2026-08-16')->where('site_id', $site->id)->first();

        $this->assertNotNull($day1, 'Day 1 summary should be generated');
        $this->assertNotNull($day2, 'Day 2 summary should be generated');
        $this->assertSame(1, $day1->event_count);
        $this->assertSame(1, $day2->event_count);
    }

    public function test_site_scoping_strict_isolation(): void
    {
        $user = $this->userWith(['report.view']);
        $siteAlpha = Site::factory()->create(['name' => 'Campus Alpha']);
        $siteBeta = Site::factory()->create(['name' => 'Campus Beta']);
        $today = '2026-09-01';

        EventDailySummary::factory()->create([
            'site_id' => $siteAlpha->id,
            'summary_date' => $today,
            'event_count' => 15,
            'decision' => 'ALLOW',
            'event_type' => 'FACE_RECOGNIZED',
        ]);
        EventDailySummary::factory()->create([
            'site_id' => $siteBeta->id,
            'summary_date' => $today,
            'event_count' => 85,
            'decision' => 'DENY',
            'event_type' => 'FACE_RECOGNIZED',
        ]);

        // Scoped to Site Alpha
        $resAlpha = $this->actingAs($user, 'web')->getJson("/api/v1/reports/daily?date={$today}&site_uuid={$siteAlpha->uuid}");
        $resAlpha->assertOk();
        $this->assertSame(15, $resAlpha->json('data.totals.detected'));
        $this->assertSame(15, $resAlpha->json('data.totals.allow'));
        $this->assertSame(0, $resAlpha->json('data.totals.deny'));

        // Scoped to Site Beta
        $resBeta = $this->actingAs($user, 'web')->getJson("/api/v1/reports/daily?date={$today}&site_uuid={$siteBeta->uuid}");
        $resBeta->assertOk();
        $this->assertSame(85, $resBeta->json('data.totals.detected'));
        $this->assertSame(0, $resBeta->json('data.totals.allow'));
        $this->assertSame(85, $resBeta->json('data.totals.deny'));
    }

    public function test_backfill_strategy_is_idempotent(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->create(['site_id' => $site->id]);
        $camera = Camera::factory()->create(['site_id' => $site->id, 'zone_id' => $zone->id]);
        $person = Person::factory()->create();
        $day = Carbon::parse('2026-07-10 00:00:00');

        $event = AccessEvent::factory()->create([
            'site_id' => $site->id,
            'zone_id' => $zone->id,
            'camera_id' => $camera->id,
            'person_id' => $person->id,
            'occurred_at' => $day->copy()->addHours(14),
        ]);
        AccessDecision::factory()->for($event, 'event')->create([
            'decision' => 'ALLOW',
        ]);

        $refreshService = app(EventSummaryRefreshService::class);

        // Run backfill 1
        $refreshService->refreshDay($day->copy());
        $count1 = EventDailySummary::whereDate('summary_date', '2026-07-10')->where('site_id', $site->id)->count();
        $total1 = EventDailySummary::whereDate('summary_date', '2026-07-10')->where('site_id', $site->id)->sum('event_count');

        // Run backfill 2 (idempotent re-run)
        $refreshService->refreshDay($day->copy());
        $count2 = EventDailySummary::whereDate('summary_date', '2026-07-10')->where('site_id', $site->id)->count();
        $total2 = EventDailySummary::whereDate('summary_date', '2026-07-10')->where('site_id', $site->id)->sum('event_count');

        $this->assertSame($count1, $count2);
        $this->assertSame($total1, $total2);
        $this->assertSame(1, (int) $total2);
    }
}
