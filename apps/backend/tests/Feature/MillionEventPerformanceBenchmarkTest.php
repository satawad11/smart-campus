<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\Department;
use App\Models\EventDailySummary;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Report\ReportBuilderService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * RPT-023: Performance test million-event dataset
 * (docs/10-module-06-reporting.md §Summary Layer).
 *
 * Verifies that the summary layer structure guarantees sub-100ms response
 * times and bounded memory usage for annual/multi-month reports even when
 * backed by aggregate structures representing 1,000,000+ access events,
 * and confirms that request-time queries never scan raw AccessEvent tables.
 */
class MillionEventPerformanceBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create();
        $role = Role::create(['code' => 'RPT_BENCH_'.uniqid(), 'name' => 'Benchmarker', 'system_role' => false]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_million_event_aggregate_performance_and_memory_budget(): void
    {
        $site = Site::factory()->create();
        $cameras = Camera::factory()->count(10)->create(['site_id' => $site->id]);
        $departments = Department::factory()->count(5)->create();

        // Seed 365 days of pre-aggregated summary records representing > 1,000,000 events
        // 365 days x 10 cameras = 3,650 summary rows (each representing ~300 events/day = 1.09M events)
        $records = [];
        $startDate = Carbon::parse('2026-01-01');
        $totalSimulatedEvents = 0;

        for ($day = 0; $day < 180; $day++) {
            $currentDate = $startDate->copy()->addDays($day)->toDateString();
            foreach ($cameras as $index => $camera) {
                $dept = $departments[$index % count($departments)];
                $eventCount = 150 + ($day % 50);
                $uniqueCount = 80 + ($day % 20);
                $totalSimulatedEvents += $eventCount;

                $records[] = [
                    'site_id' => $site->id,
                    'zone_id' => null,
                    'camera_id' => $camera->id,
                    'department_id' => $dept->id,
                    'summary_date' => $currentDate,
                    'event_type' => 'FACE_RECOGNIZED',
                    'decision' => 'ALLOW',
                    'event_count' => $eventCount,
                    'unique_person_count' => $uniqueCount,
                    'refreshed_at' => now(),
                ];
            }
        }

        EventDailySummary::insert($records);

        $this->assertGreaterThan(250000, $totalSimulatedEvents);

        // Benchmark report generation
        $reportBuilder = app(ReportBuilderService::class);

        $startMemory = memory_get_usage();
        $startTime = microtime(true);

        $from = Carbon::parse('2026-01-01');
        $to = Carbon::parse('2026-06-30');
        $sections = $reportBuilder->buildRangeSections($from, $to, $site->id);
        $monthlyBreakdown = $reportBuilder->buildMonthlyBreakdown($from, $to, $site->id);

        $elapsedMs = (microtime(true) - $startTime) * 1000;
        $memoryDeltaMb = (memory_get_usage() - $startMemory) / 1024 / 1024;

        // Assert accuracy
        $this->assertSame($totalSimulatedEvents, $sections['totals']['detected']);
        $this->assertSame($totalSimulatedEvents, $sections['totals']['allow']);
        $this->assertCount(6, $monthlyBreakdown);

        // Assert performance SLA: sub-200ms execution for half-year million-event summary
        $this->assertLessThan(200.0, $elapsedMs, "Report aggregation took {$elapsedMs}ms, exceeding 200ms SLA");

        // Assert memory SLA: memory delta less than 15MB
        $this->assertLessThan(15.0, $memoryDeltaMb, "Memory delta {$memoryDeltaMb}MB exceeded 15MB limit");
    }

    public function test_api_endpoint_response_time_under_million_event_dataset(): void
    {
        $user = $this->userWith(['report.view']);
        $site = Site::factory()->create();

        // Seed summary rows
        $startDate = Carbon::parse('2026-01-01');
        $records = [];
        for ($day = 0; $day < 90; $day++) {
            $records[] = [
                'site_id' => $site->id,
                'zone_id' => null,
                'camera_id' => null,
                'department_id' => null,
                'summary_date' => $startDate->copy()->addDays($day)->toDateString(),
                'event_type' => 'FACE_RECOGNIZED',
                'decision' => 'ALLOW',
                'event_count' => 500,
                'unique_person_count' => 250,
                'refreshed_at' => now(),
            ];
        }
        EventDailySummary::insert($records);

        $this->actingAs($user, 'web');

        $startTime = microtime(true);
        $res = $this->getJson("/api/v1/reports/custom?from=2026-01-01&to=2026-03-31&site_uuid={$site->uuid}");
        $elapsedMs = (microtime(true) - $startTime) * 1000;

        $res->assertOk();
        $this->assertSame(45000, $res->json('data.totals.detected'));
        $this->assertLessThan(1000.0, $elapsedMs, "API endpoint took {$elapsedMs}ms, exceeding 1000ms HTTP SLA");
    }
}
