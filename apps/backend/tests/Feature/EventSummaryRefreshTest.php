<?php

namespace Tests\Feature;

use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\Camera;
use App\Models\Department;
use App\Models\EventDailySummary;
use App\Models\EventHourlySummary;
use App\Models\Person;
use App\Models\Site;
use App\Models\StudentProfile;
use App\Models\Zone;
use App\Services\Report\EventSummaryRefreshService;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RPT-004 — summary refresh/backfill jobs. EventSummaryRefreshService is a
 * pure re-derivation from raw AccessEvent/AccessDecision (RPT-003's own
 * "raw events remain source of truth"), so every assertion here checks the
 * summary table against hand-built raw rows, never the other way around.
 */
class EventSummaryRefreshTest extends TestCase
{
    use RefreshDatabase;

    private EventSummaryRefreshService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EventTypeSeeder::class);
        $this->service = app(EventSummaryRefreshService::class);
    }

    public function test_refresh_hour_aggregates_event_count_and_unique_person_count_separately(): void
    {
        $hour = now()->startOfHour();
        $site = Site::factory()->create();
        $person = Person::factory()->create();

        // Same person recognized twice within the hour: 2 events, 1 unique person.
        foreach (range(1, 2) as $i) {
            $event = AccessEvent::factory()->create([
                'site_id' => $site->id, 'person_id' => $person->id,
                'occurred_at' => $hour->copy()->addMinutes($i),
            ]);
            AccessDecision::factory()->for($event, 'event')->create(['decision' => 'ALLOW']);
        }

        $this->service->refreshHour($hour->copy());

        $summary = EventHourlySummary::where('site_id', $site->id)->where('decision', 'ALLOW')->firstOrFail();
        $this->assertSame(2, $summary->event_count);
        $this->assertSame(1, $summary->unique_person_count);
    }

    public function test_refresh_hour_groups_by_the_latest_decision_never_the_original(): void
    {
        $hour = now()->startOfHour();
        $site = Site::factory()->create();
        $event = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => $hour->copy()->addMinutes(5)]);
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'REVIEW']);
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'ALLOW']); // officer override

        $this->service->refreshHour($hour->copy());

        $this->assertDatabaseHas('event_hourly_summaries', ['site_id' => $site->id, 'decision' => 'ALLOW', 'event_count' => 1]);
        $this->assertDatabaseMissing('event_hourly_summaries', ['site_id' => $site->id, 'decision' => 'REVIEW']);
    }

    public function test_refresh_hour_resolves_department_through_the_persons_student_profile(): void
    {
        $hour = now()->startOfHour();
        $site = Site::factory()->create();
        $department = Department::factory()->create();
        $person = Person::factory()->create();
        StudentProfile::factory()->for($person)->create(['department_id' => $department->id]);
        $event = AccessEvent::factory()->create(['site_id' => $site->id, 'person_id' => $person->id, 'occurred_at' => $hour->copy()->addMinutes(1)]);
        AccessDecision::factory()->for($event, 'event')->create();

        $this->service->refreshHour($hour->copy());

        $this->assertDatabaseHas('event_hourly_summaries', ['site_id' => $site->id, 'department_id' => $department->id]);
    }

    public function test_refresh_hour_handles_a_person_with_no_student_profile_as_a_null_department(): void
    {
        $hour = now()->startOfHour();
        $site = Site::factory()->create();
        $person = Person::factory()->create();
        $event = AccessEvent::factory()->create(['site_id' => $site->id, 'person_id' => $person->id, 'occurred_at' => $hour->copy()->addMinutes(1)]);
        AccessDecision::factory()->for($event, 'event')->create();

        $this->service->refreshHour($hour->copy());

        $summary = EventHourlySummary::where('site_id', $site->id)->firstOrFail();
        $this->assertNull($summary->department_id);
    }

    public function test_refresh_hour_handles_unknown_person_events_with_no_person_id(): void
    {
        $hour = now()->startOfHour();
        $site = Site::factory()->create();
        $event = AccessEvent::factory()->create([
            'site_id' => $site->id, 'person_id' => null, 'event_type' => 'UNKNOWN_PERSON',
            'occurred_at' => $hour->copy()->addMinutes(1),
        ]);
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'UNKNOWN']);

        $this->service->refreshHour($hour->copy());

        $summary = EventHourlySummary::where('site_id', $site->id)->where('event_type', 'UNKNOWN_PERSON')->firstOrFail();
        $this->assertSame(1, $summary->event_count);
        $this->assertSame(0, $summary->unique_person_count);
    }

    public function test_refresh_hour_separates_buckets_by_zone_and_camera(): void
    {
        $hour = now()->startOfHour();
        $site = Site::factory()->create();
        $zoneA = Zone::factory()->for($site)->create();
        $zoneB = Zone::factory()->for($site)->create();
        $cameraA = Camera::factory()->create(['site_id' => $site->id, 'zone_id' => $zoneA->id]);
        $cameraB = Camera::factory()->create(['site_id' => $site->id, 'zone_id' => $zoneB->id]);

        foreach ([$cameraA, $cameraB] as $camera) {
            $event = AccessEvent::factory()->create([
                'site_id' => $site->id, 'zone_id' => $camera->zone_id, 'camera_id' => $camera->id,
                'occurred_at' => $hour->copy()->addMinutes(1),
            ]);
            AccessDecision::factory()->for($event, 'event')->create();
        }

        $this->service->refreshHour($hour->copy());

        $this->assertSame(2, EventHourlySummary::where('site_id', $site->id)->count());
        $this->assertDatabaseHas('event_hourly_summaries', ['camera_id' => $cameraA->id, 'event_count' => 1]);
        $this->assertDatabaseHas('event_hourly_summaries', ['camera_id' => $cameraB->id, 'event_count' => 1]);
    }

    public function test_events_outside_the_hour_window_are_excluded(): void
    {
        $hour = now()->startOfHour();
        $site = Site::factory()->create();
        $inWindow = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => $hour->copy()->addMinutes(30)]);
        AccessDecision::factory()->for($inWindow, 'event')->create();
        $beforeWindow = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => $hour->copy()->subMinute()]);
        AccessDecision::factory()->for($beforeWindow, 'event')->create();
        $afterWindow = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => $hour->copy()->addHour()]);
        AccessDecision::factory()->for($afterWindow, 'event')->create();

        $this->service->refreshHour($hour->copy());

        $summary = EventHourlySummary::where('site_id', $site->id)->firstOrFail();
        $this->assertSame(1, $summary->event_count);
    }

    public function test_re_running_refresh_hour_is_idempotent_and_updates_rather_than_duplicates(): void
    {
        $hour = now()->startOfHour();
        $site = Site::factory()->create();
        $event = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => $hour->copy()->addMinutes(1)]);
        AccessDecision::factory()->for($event, 'event')->create();

        $this->service->refreshHour($hour->copy());
        $this->assertSame(1, EventHourlySummary::count());
        $firstRefreshedAt = EventHourlySummary::first()->refreshed_at;

        // A second event lands in the same bucket before the next refresh.
        $secondEvent = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => $hour->copy()->addMinutes(2)]);
        AccessDecision::factory()->for($secondEvent, 'event')->create();

        $this->service->refreshHour($hour->copy());

        $this->assertSame(1, EventHourlySummary::count()); // still one row, updated not duplicated
        $summary = EventHourlySummary::first();
        $this->assertSame(2, $summary->event_count);
        $this->assertTrue($summary->refreshed_at->gte($firstRefreshedAt));
    }

    public function test_re_running_refresh_day_is_idempotent_and_updates_rather_than_duplicates(): void
    {
        $day = now()->startOfDay();
        $site = Site::factory()->create();
        $event = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => $day->copy()->addHours(5)]);
        AccessDecision::factory()->for($event, 'event')->create();

        $this->service->refreshDay($day->copy());
        $this->assertSame(1, EventDailySummary::count());

        $secondEvent = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => $day->copy()->addHours(10)]);
        AccessDecision::factory()->for($secondEvent, 'event')->create();

        $this->service->refreshDay($day->copy());

        $this->assertSame(1, EventDailySummary::count()); // still one row, updated not duplicated
        $this->assertSame(2, EventDailySummary::first()->event_count);
    }

    public function test_refresh_day_aggregates_across_the_whole_calendar_day(): void
    {
        $day = now()->startOfDay();
        $site = Site::factory()->create();
        foreach ([1, 12, 23] as $hourOffset) {
            $event = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => $day->copy()->addHours($hourOffset)]);
            AccessDecision::factory()->for($event, 'event')->create();
        }

        $this->service->refreshDay($day->copy());

        $summary = EventDailySummary::where('site_id', $site->id)->firstOrFail();
        $this->assertSame(3, $summary->event_count);
        $this->assertSame($day->toDateString(), $summary->summary_date->toDateString());
    }

    public function test_artisan_command_refreshes_the_default_trailing_window(): void
    {
        $site = Site::factory()->create();
        $event = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => now()->subHour()]);
        AccessDecision::factory()->for($event, 'event')->create();

        $this->artisan('events:refresh-summaries')->assertSuccessful();

        $this->assertGreaterThan(0, EventHourlySummary::count());
        $this->assertGreaterThan(0, EventDailySummary::count());
    }

    public function test_artisan_command_supports_an_explicit_backfill_range(): void
    {
        $site = Site::factory()->create();
        $oldEvent = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => now()->subDays(10)]);
        AccessDecision::factory()->for($oldEvent, 'event')->create();

        $this->artisan('events:refresh-summaries', [
            '--from' => now()->subDays(10)->startOfDay()->toISOString(),
            '--to' => now()->subDays(9)->startOfDay()->toISOString(),
        ])->assertSuccessful();

        $this->assertDatabaseHas('event_hourly_summaries', ['site_id' => $site->id]);
    }

    public function test_artisan_command_hourly_only_skips_daily_summaries(): void
    {
        $site = Site::factory()->create();
        $event = AccessEvent::factory()->create(['site_id' => $site->id, 'occurred_at' => now()]);
        AccessDecision::factory()->for($event, 'event')->create();

        $this->artisan('events:refresh-summaries', ['--hourly-only' => true])->assertSuccessful();

        $this->assertGreaterThan(0, EventHourlySummary::count());
        $this->assertSame(0, EventDailySummary::count());
    }

    public function test_artisan_command_rejects_a_from_after_to(): void
    {
        $this->artisan('events:refresh-summaries', [
            '--from' => now()->toISOString(),
            '--to' => now()->subHour()->toISOString(),
        ])->assertFailed();
    }
}
