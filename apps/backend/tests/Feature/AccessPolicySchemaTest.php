<?php

namespace Tests\Feature;

use App\Enums\AccessDecisionOutcome;
use App\Enums\StudentAcademicStatus;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AccessPolicy;
use App\Models\EventType;
use App\Models\Site;
use App\Models\Zone;
use Database\Seeders\AccessPolicySeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * EVT-002 — access policy schema / seed defaults tests.
 *
 * Schema + idempotent seed only. The matcher that evaluates these
 * policies against an AccessContext is EVT-006 — not built yet.
 */
class AccessPolicySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_policies_table_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('access_policies'));
        $this->assertTrue(Schema::hasColumns('access_policies', [
            'id', 'uuid', 'name', 'subject_type', 'subject_status', 'site_id', 'zone_id',
            'event_type', 'decision', 'reason_code', 'priority', 'enabled', 'valid_from',
            'valid_until', 'created_at', 'updated_at',
        ]));
    }

    public function test_access_decisions_now_has_access_policy_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('access_decisions', 'access_policy_id'));
    }

    public function test_access_policy_carries_no_person_or_biometric_fields(): void
    {
        // A policy is config, never tied to one person's identity/biometrics.
        foreach (['person_id', 'embedding', 'face_profile_id', 'track_id'] as $forbidden) {
            $this->assertFalse(Schema::hasColumn('access_policies', $forbidden));
        }
    }

    public function test_wildcard_dimensions_are_nullable(): void
    {
        $policy = AccessPolicy::factory()->create([
            'subject_type' => null,
            'subject_status' => null,
            'site_id' => null,
            'zone_id' => null,
            'event_type' => null,
        ]);

        $this->assertNull($policy->subject_type);
        $this->assertNull($policy->event_type);
    }

    public function test_policy_can_scope_to_a_site_and_zone(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();

        $policy = AccessPolicy::factory()->create(['site_id' => $site->id, 'zone_id' => $zone->id]);

        $this->assertTrue($policy->site->is($site));
        $this->assertTrue($policy->zone->is($zone));
    }

    public function test_policy_event_type_must_reference_an_existing_event_type_code(): void
    {
        $eventType = EventType::factory()->create(['code' => 'CUSTOM_EVENT']);
        $policy = AccessPolicy::factory()->create(['event_type' => 'CUSTOM_EVENT']);

        $this->assertTrue($policy->eventType->is($eventType));
    }

    public function test_policy_decision_casts_to_enum(): void
    {
        $policy = AccessPolicy::factory()->create(['decision' => AccessDecisionOutcome::DENY]);
        $this->assertSame(AccessDecisionOutcome::DENY, $policy->decision);
    }

    public function test_a_decision_can_reference_the_policy_that_produced_it(): void
    {
        $policy = AccessPolicy::factory()->create();
        $event = AccessEvent::factory()->create();
        $decision = AccessDecision::factory()->for($event, 'event')->create([
            'access_policy_id' => $policy->id,
            'decision' => $policy->decision,
            'reason_code' => $policy->reason_code,
        ]);

        $this->assertTrue($decision->policy->is($policy));
        $this->assertTrue($policy->decisions->contains($decision));
    }

    public function test_a_decision_may_have_no_policy_at_all(): void
    {
        $decision = AccessDecision::factory()->create(['access_policy_id' => null]);
        $this->assertNull($decision->policy);
    }

    // ================= Default policy seed =================

    public function test_seeder_creates_the_documented_default_policies(): void
    {
        $this->seed(EventTypeSeeder::class);
        $this->seed(AccessPolicySeeder::class);

        $this->assertDatabaseCount('access_policies', 17);

        $expected = [
            'Liveness Failed -> Review' => 'REVIEW',
            'Unrecognized Person -> Unknown' => 'UNKNOWN',
            'Student Active -> Allow' => 'ALLOW',
            'Student Leave -> Review' => 'REVIEW',
            'Student Suspended -> Review' => 'REVIEW',
            'Student Withdrawn -> Review' => 'REVIEW',
            'Student Graduated -> Review' => 'REVIEW',
            'Student Expelled -> Deny' => 'DENY',
            'Staff Active -> Allow' => 'ALLOW',
            'STAFF LEAVE -> Review' => 'REVIEW',
            'STAFF SUSPENDED -> Review' => 'REVIEW',
            'STAFF RESIGNED -> Review' => 'REVIEW',
            'STAFF RETIRED -> Review' => 'REVIEW',
            'Visitor Active Visit -> Allow' => 'ALLOW',
            'VISITOR NO_ACTIVE_VISIT -> Review' => 'REVIEW',
            'Parent With Active Student -> Allow' => 'ALLOW',
            'PARENT NO_ACTIVE_STUDENT -> Review' => 'REVIEW',
        ];

        foreach ($expected as $name => $decision) {
            $this->assertDatabaseHas('access_policies', ['name' => $name, 'decision' => $decision]);
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(EventTypeSeeder::class);
        $this->seed(AccessPolicySeeder::class);
        $this->seed(AccessPolicySeeder::class);

        $this->assertDatabaseCount('access_policies', 17);
    }

    public function test_student_status_policies_cover_every_studentacademicstatus_value(): void
    {
        $this->seed(EventTypeSeeder::class);
        $this->seed(AccessPolicySeeder::class);

        foreach (StudentAcademicStatus::cases() as $status) {
            if ($status === StudentAcademicStatus::UNKNOWN) {
                continue; // not part of the documented default set — genuinely unmapped.
            }
            $this->assertDatabaseHas('access_policies', [
                'subject_type' => 'STUDENT',
                'subject_status' => $status->value,
            ]);
        }
    }

    public function test_liveness_and_unknown_policies_are_not_scoped_to_a_subject(): void
    {
        $this->seed(EventTypeSeeder::class);
        $this->seed(AccessPolicySeeder::class);

        $liveness = AccessPolicy::where('event_type', 'LIVENESS_FAILED')->firstOrFail();
        $unknown = AccessPolicy::where('event_type', 'UNKNOWN_PERSON')->firstOrFail();

        $this->assertNull($liveness->subject_type);
        $this->assertNull($unknown->subject_type);
        $this->assertSame(AccessDecisionOutcome::REVIEW, $liveness->decision);
        $this->assertSame(AccessDecisionOutcome::UNKNOWN, $unknown->decision);
    }
}
