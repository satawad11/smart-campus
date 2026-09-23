<?php

namespace Tests\Feature;

use App\Enums\AccessDecisionOutcome;
use App\Enums\DecisionSource;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\Camera;
use App\Models\EventType;
use App\Models\Person;
use App\Models\Site;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * EVT-001 — event type / access event / access decision schema tests.
 *
 * Schema-only task: migrations, models, relationships. No ingestion
 * service/API yet (EVT-003+), no access_policies table yet (EVT-002 —
 * access_decisions.access_policy_id does not exist until that task adds
 * it). Active connection: SQLite locally, real PostgreSQL in docker
 * verification.
 */
class EventPolicySchemaTest extends TestCase
{
    use RefreshDatabase;

    // ================= Tables / columns =================

    public function test_event_types_table_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('event_types'));
        $this->assertTrue(Schema::hasColumns('event_types', [
            'id', 'code', 'name_th', 'name_en', 'category', 'severity', 'active', 'created_at', 'updated_at',
        ]));
    }

    public function test_access_events_table_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('access_events'));
        $this->assertTrue(Schema::hasColumns('access_events', [
            'id', 'uuid', 'person_id', 'site_id', 'zone_id', 'camera_id', 'ai_node_id', 'event_type',
            'occurred_at', 'received_at', 'face_score', 'liveness_score', 'quality_score', 'track_id',
            'snapshot_path', 'metadata', 'created_at',
        ]));
    }

    public function test_access_decisions_table_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('access_decisions'));
        $this->assertTrue(Schema::hasColumns('access_decisions', [
            'id', 'uuid', 'event_id', 'person_id', 'decision', 'reason_code', 'decision_source',
            'decided_by_user_id', 'reason_text', 'created_at',
        ]));
    }

    // ================= Immutability (append-only) =================

    public function test_access_events_has_no_updated_at_column(): void
    {
        $this->assertFalse(Schema::hasColumn('access_events', 'updated_at'));
    }

    public function test_access_decisions_has_no_updated_at_column(): void
    {
        $this->assertFalse(Schema::hasColumn('access_decisions', 'updated_at'));
    }

    // ================= Event != Decision invariant =================

    public function test_access_events_carries_no_decision_or_policy_fields(): void
    {
        foreach (['decision', 'access_policy_id', 'reason_code', 'allow', 'deny', 'door_state'] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn('access_events', $forbidden),
                "access_events must never carry a decision field ({$forbidden}) — Event != Decision."
            );
        }
    }

    public function test_event_types_carries_no_decision_or_policy_fields(): void
    {
        foreach (['decision', 'access_policy_id', 'allow', 'deny', 'door_state'] as $forbidden) {
            $this->assertFalse(Schema::hasColumn('event_types', $forbidden));
        }
    }

    // ================= Enum values =================

    public function test_decision_outcome_enum_is_exactly_allow_review_deny_unknown(): void
    {
        $this->assertSame(
            ['ALLOW', 'REVIEW', 'DENY', 'UNKNOWN'],
            array_column(AccessDecisionOutcome::cases(), 'value'),
        );
    }

    public function test_decision_source_enum_is_exactly_system_policy_officer(): void
    {
        $this->assertSame(
            ['SYSTEM', 'POLICY', 'OFFICER'],
            array_column(DecisionSource::cases(), 'value'),
        );
    }

    public function test_invalid_decision_value_is_rejected(): void
    {
        // The enum cast on AccessDecision::$casts rejects an invalid value
        // at the PHP layer (fail-fast) before it would even reach a raw
        // DB CHECK/enum constraint.
        $event = AccessEvent::factory()->create();

        $this->expectException(\ValueError::class);
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'MAYBE']);
    }

    // ================= Relationships / casts =================

    public function test_event_type_relationship_resolves_by_code_not_id(): void
    {
        $eventType = EventType::factory()->create(['code' => 'UNKNOWN_PERSON']);
        $event = AccessEvent::factory()->create(['event_type' => 'UNKNOWN_PERSON']);

        $this->assertTrue($event->eventType->is($eventType));
        $this->assertSame('UNKNOWN_PERSON', $event->event_type);
    }

    public function test_access_event_belongs_to_site_camera_zone_ai_node_person(): void
    {
        $person = Person::factory()->create();
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();
        $camera = Camera::factory()->create(['site_id' => $site->id, 'zone_id' => $zone->id]);

        $event = AccessEvent::factory()->create([
            'person_id' => $person->id,
            'site_id' => $site->id,
            'zone_id' => $zone->id,
            'camera_id' => $camera->id,
        ]);

        $this->assertTrue($event->person->is($person));
        $this->assertTrue($event->site->is($site));
        $this->assertTrue($event->zone->is($zone));
        $this->assertTrue($event->camera->is($camera));
    }

    public function test_access_decision_belongs_to_event_and_appears_in_events_decisions(): void
    {
        $event = AccessEvent::factory()->create();
        $decision = AccessDecision::factory()->for($event, 'event')->create();

        $this->assertTrue($decision->event->is($event));
        $this->assertTrue($event->decisions->contains($decision));
    }

    public function test_access_decision_casts_decision_and_decision_source_to_enums(): void
    {
        $decision = AccessDecision::factory()->create([
            'decision' => AccessDecisionOutcome::DENY,
            'decision_source' => DecisionSource::POLICY,
        ]);

        $this->assertSame(AccessDecisionOutcome::DENY, $decision->decision);
        $this->assertSame(DecisionSource::POLICY, $decision->decision_source);
    }

    public function test_officer_decision_can_reference_the_deciding_user(): void
    {
        $user = User::factory()->create();
        $decision = AccessDecision::factory()->create([
            'decision_source' => DecisionSource::OFFICER,
            'decided_by_user_id' => $user->id,
        ]);

        $this->assertTrue($decision->decidedBy->is($user));
    }

    // ================= Multiple decisions per event (append-only) =================

    public function test_an_event_can_accumulate_multiple_decisions_without_rewriting_the_first(): void
    {
        $event = AccessEvent::factory()->create();
        $system = AccessDecision::factory()->for($event, 'event')->create([
            'decision' => AccessDecisionOutcome::REVIEW,
            'decision_source' => DecisionSource::SYSTEM,
        ]);

        $officer = AccessDecision::factory()->for($event, 'event')->create([
            'decision' => AccessDecisionOutcome::ALLOW,
            'decision_source' => DecisionSource::OFFICER,
        ]);

        $this->assertCount(2, $event->decisions()->get());
        $system->refresh();
        $this->assertSame(AccessDecisionOutcome::REVIEW, $system->decision);
        $this->assertSame(AccessDecisionOutcome::ALLOW, $officer->decision);
    }

    // ================= Delete behavior (never cascade-delete history) =================

    public function test_deleting_a_camera_nulls_the_event_reference_rather_than_deleting_the_event(): void
    {
        $camera = Camera::factory()->create();
        $event = AccessEvent::factory()->create(['camera_id' => $camera->id]);

        $camera->forceDelete();

        $this->assertDatabaseHas('access_events', ['id' => $event->id, 'camera_id' => null]);
    }

    public function test_deleting_a_site_with_events_is_restricted(): void
    {
        $site = Site::factory()->create();
        AccessEvent::factory()->create(['site_id' => $site->id]);

        $this->expectException(QueryException::class);
        $site->delete();
    }
}
