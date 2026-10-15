<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\Department;
use App\Models\Site;
use App\Models\StudentProfile;
use App\Models\Zone;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V1 audit A-22: summary refresh/backfill from ~1M RAW events (RPT-004,
 * RPT-023). The RPT-023 benchmark seeds pre-aggregated rows; this test
 * generates raw access_events + access_decisions (5% with a later officer
 * override, so "latest decision wins" is exercised) and runs
 * events:refresh-summaries over the whole range on PostgreSQL.
 *
 * Opt-in (minutes, PostgreSQL only):
 *   RUN_SCALE_TESTS=1 RUNNER=local bash scripts/test-backend-pgsql.sh --filter RawEventSummaryRefreshScaleTest
 * SCALE_EVENTS (default 1000000) and SCALE_DAYS (default 30) size the dataset.
 */
class RawEventSummaryRefreshScaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_from_raw_events_is_complete_correct_and_bounded(): void
    {
        if (! getenv('RUN_SCALE_TESTS') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Scale test: set RUN_SCALE_TESTS=1 and run on PostgreSQL.');
        }

        $events = (int) (getenv('SCALE_EVENTS') ?: 1_000_000);
        $days = (int) (getenv('SCALE_DAYS') ?: 30);
        $start = Carbon::parse('2026-09-01 00:00:00', 'UTC');

        $this->seed(EventTypeSeeder::class);
        $site = Site::factory()->create();
        $zones = Zone::factory()->count(2)->create(['site_id' => $site->id]);
        $cameraIds = [];
        foreach (range(0, 9) as $i) {
            $cameraIds[] = Camera::factory()->create([
                'site_id' => $site->id, 'zone_id' => $zones[$i % 2]->id,
            ])->id;
        }
        $departments = Department::factory()->count(5)->create();
        $personIds = [];
        foreach (range(0, 199) as $i) {
            $personIds[] = StudentProfile::factory()->create([
                'department_id' => $departments[$i % 5]->id,
            ])->person_id;
        }

        $t0 = microtime(true);
        DB::statement('SET LOCAL synchronous_commit TO OFF');
        DB::statement(<<<'SQL'
            INSERT INTO access_events (uuid, person_id, site_id, zone_id, camera_id, event_type, occurred_at, received_at, created_at)
            SELECT gen_random_uuid(),
                   CASE WHEN g % 10 = 0 THEN NULL ELSE (?::bigint[])[1 + (g % 200)] END,
                   ?, c.zone_id, c.id,
                   CASE WHEN g % 10 = 0 THEN 'UNKNOWN_PERSON' ELSE 'FACE_RECOGNIZED' END,
                   ?::timestamptz + ((g::double precision / ?) * ? * interval '1 day'),
                   now(), now()
            FROM generate_series(0, ? - 1) AS g
            JOIN cameras c ON c.id = (?::bigint[])[1 + (g % 10)]
            SQL, ['{'.implode(',', $personIds).'}', $site->id, $start->toIso8601String(), $events, $days, $events, '{'.implode(',', $cameraIds).'}']);
        DB::statement(<<<'SQL'
            INSERT INTO access_decisions (uuid, event_id, person_id, decision, decision_source, created_at)
            SELECT gen_random_uuid(), e.id, e.person_id,
                   CASE WHEN e.person_id IS NULL THEN 'UNKNOWN' WHEN e.id % 7 = 0 THEN 'REVIEW' ELSE 'ALLOW' END,
                   'SYSTEM', e.occurred_at
            FROM access_events e
            SQL);
        // 5% officer overrides REVIEW/ALLOW -> DENY; the summary must count the latest decision only
        DB::statement(<<<'SQL'
            INSERT INTO access_decisions (uuid, event_id, person_id, decision, decision_source, created_at)
            SELECT gen_random_uuid(), e.id, e.person_id, 'DENY', 'OFFICER', e.occurred_at + interval '5 minutes'
            FROM access_events e WHERE e.person_id IS NOT NULL AND e.id % 20 = 0
            SQL);
        DB::statement('ANALYZE access_events');
        DB::statement('ANALYZE access_decisions');
        $seedSeconds = microtime(true) - $t0;

        $this->assertSame($events, (int) DB::table('access_events')->count());

        $t1 = microtime(true);
        $exit = Artisan::call('events:refresh-summaries', [
            '--from' => $start->toIso8601String(),
            '--to' => $start->copy()->addDays($days)->toIso8601String(),
        ]);
        $refreshSeconds = microtime(true) - $t1;
        $this->assertSame(0, $exit);

        // Complete: every raw event is counted exactly once, hourly and daily
        $this->assertSame($events, (int) DB::table('event_daily_summaries')->sum('event_count'));
        $this->assertSame($events, (int) DB::table('event_hourly_summaries')->sum('event_count'));

        // Correct: latest decision wins (officer DENY overrides)
        $expectedDeny = (int) DB::table('access_events')->whereNotNull('person_id')->whereRaw('id % 20 = 0')->count();
        $this->assertSame($expectedDeny, (int) DB::table('event_daily_summaries')->where('decision', 'DENY')->sum('event_count'));
        $expectedUnknown = (int) DB::table('access_events')->whereNull('person_id')->count();
        $this->assertSame($expectedUnknown, (int) DB::table('event_daily_summaries')->where('decision', 'UNKNOWN')->sum('event_count'));

        // Idempotent: a second refresh of one day changes nothing
        $before = (int) DB::table('event_daily_summaries')->count();
        Artisan::call('events:refresh-summaries', ['--from' => $start->toIso8601String(), '--to' => $start->copy()->addDay()->toIso8601String()]);
        $this->assertSame($before, (int) DB::table('event_daily_summaries')->count());
        $this->assertSame($events, (int) DB::table('event_daily_summaries')->sum('event_count'));

        // Bounded: the routine 2-hour refresh stays fast at this table size
        $t2 = microtime(true);
        Artisan::call('events:refresh-summaries', [
            '--from' => $start->copy()->addDays(10)->toIso8601String(),
            '--to' => $start->copy()->addDays(10)->addHours(2)->toIso8601String(),
        ]);
        $routineSeconds = microtime(true) - $t2;

        fwrite(STDERR, sprintf(
            "\n[A-22] events=%d days=%d seed=%.1fs full_backfill=%.1fs (%d hours + %d days) routine_2h_refresh=%.2fs\n",
            $events, $days, $seedSeconds, $refreshSeconds, $days * 24, $days, $routineSeconds,
        ));

        $this->assertLessThan(10.0, $routineSeconds, 'The scheduled 2-hour refresh must stay well under its interval.');
    }
}
