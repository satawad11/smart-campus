<?php

namespace Tests\Feature;

use App\Enums\AccessDecisionOutcome;
use App\Models\Camera;
use App\Models\Department;
use App\Models\EventHourlySummary;
use App\Models\Site;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RPT-003 — hourly/daily summary schema (docs/10-module-06-reporting.md
 * "Summary Layer"). A derived cache, never a source of truth: no code path
 * outside RPT-004's refresh job should ever write these rows, and nothing
 * here duplicates data AccessEvent/AccessDecision don't already have.
 */
class EventSummarySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_hourly_and_daily_summary_tables_exist_with_expected_columns(): void
    {
        foreach (['event_hourly_summaries', 'event_daily_summaries'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            foreach (['site_id', 'zone_id', 'camera_id', 'department_id', 'event_type', 'decision', 'event_count', 'unique_person_count', 'refreshed_at'] as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "{$table} must have column {$column}.");
            }
        }
        $this->assertTrue(Schema::hasColumn('event_hourly_summaries', 'hour_bucket'));
        $this->assertTrue(Schema::hasColumn('event_daily_summaries', 'summary_date'));
    }

    public function test_event_count_and_unique_person_count_are_separate_columns(): void
    {
        // docs/10 Accuracy Rules: "distinguish total events vs unique
        // persons" — the same person recognized 5 times is 5 events but 1
        // unique person, so these must never be the same column.
        $summary = EventHourlySummary::factory()->create(['event_count' => 5, 'unique_person_count' => 1]);

        $summary->refresh();
        $this->assertSame(5, $summary->event_count);
        $this->assertSame(1, $summary->unique_person_count);
        $this->assertNotSame($summary->event_count, $summary->unique_person_count);
    }

    public function test_decision_cast_and_enum_matches_access_decision_outcome(): void
    {
        $summary = EventHourlySummary::factory()->create(['decision' => 'REVIEW']);

        $this->assertInstanceOf(AccessDecisionOutcome::class, $summary->decision);
        $this->assertSame('REVIEW', $summary->decision->value);
    }

    public function test_relations_resolve_to_the_correct_models(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();
        $camera = Camera::factory()->create(['site_id' => $site->id, 'zone_id' => $zone->id]);
        $department = Department::factory()->create();

        $summary = EventHourlySummary::factory()->create([
            'site_id' => $site->id,
            'zone_id' => $zone->id,
            'camera_id' => $camera->id,
            'department_id' => $department->id,
        ]);

        $this->assertInstanceOf(BelongsTo::class, $summary->site());
        $this->assertTrue($summary->site->is($site));
        $this->assertTrue($summary->zone->is($zone));
        $this->assertTrue($summary->camera->is($camera));
        $this->assertTrue($summary->department->is($department));
    }

    public function test_uniqueness_is_enforced_when_every_dimension_is_non_null(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();
        $camera = Camera::factory()->create(['site_id' => $site->id, 'zone_id' => $zone->id]);
        $department = Department::factory()->create();
        $hour = now()->startOfHour();

        EventHourlySummary::factory()->create([
            'site_id' => $site->id, 'zone_id' => $zone->id, 'camera_id' => $camera->id,
            'department_id' => $department->id, 'hour_bucket' => $hour,
            'event_type' => 'FACE_RECOGNIZED', 'decision' => 'ALLOW',
        ]);

        $this->expectException(QueryException::class);
        EventHourlySummary::factory()->create([
            'site_id' => $site->id, 'zone_id' => $zone->id, 'camera_id' => $camera->id,
            'department_id' => $department->id, 'hour_bucket' => $hour,
            'event_type' => 'FACE_RECOGNIZED', 'decision' => 'ALLOW',
        ]);
    }

    public function test_summary_tables_carry_no_biometric_or_access_decision_bypass_fields(): void
    {
        foreach (['event_hourly_summaries', 'event_daily_summaries'] as $table) {
            foreach (['embedding', 'face_vector', 'snapshot_path', 'person_id'] as $forbidden) {
                $this->assertFalse(
                    Schema::hasColumn($table, $forbidden),
                    "{$table} must never carry a per-person identifying or biometric column ({$forbidden}) — it is an aggregate, not a per-event record."
                );
            }
        }
    }

    public function test_deleting_a_site_cascades_to_its_summaries(): void
    {
        $site = Site::factory()->create();
        $summary = EventHourlySummary::factory()->create(['site_id' => $site->id]);

        $site->delete();

        $this->assertDatabaseMissing('event_hourly_summaries', ['id' => $summary->id]);
    }
}
