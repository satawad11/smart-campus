<?php

namespace Tests\Feature;

use App\Enums\AccessDecisionOutcome;
use App\Enums\DecisionSource;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Person;
use App\Models\StudentProfile;
use App\Services\Camera\AiNodeCredentialService;
use Database\Seeders\AccessPolicySeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EVT-007 — decision persistence. Wires EVT-003's ingestion together with
 * EVT-006's AccessContextResolver/AccessPolicyMatcher: a freshly-created
 * AccessEvent gets exactly one AccessDecision, evaluated from the seeded
 * (or configured) access_policies. A retried or cooldown-suppressed event
 * never gets a second one.
 */
class RecognitionEventDecisionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EventTypeSeeder::class);
        $this->seed(AccessPolicySeeder::class);
        config(['event.semantic_cooldown_seconds' => 0, 'event.unknown_cooldown_seconds' => 0]);
    }

    private function bearerFor(AiNode $node): array
    {
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'event_uuid' => (string) Str::uuid(),
            'camera_uuid' => null,
            'ai_node_uuid' => null,
            'track_id' => 'track-1',
            'occurred_at' => now()->toISOString(),
            'classification' => 'MATCH',
            'candidate_person_uuid' => null,
            'similarity' => 0.95,
            'distance' => 0.05,
            'model_code' => 'arcface-r50-v1',
            'model_version' => '1.0.0',
            'quality_score' => 0.9,
            'liveness_status' => 'REAL',
            'rejected_locally' => false,
            'reasons' => [],
        ], $overrides);
    }

    public function test_an_active_students_match_produces_an_allow_decision(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $person = Person::factory()->create();
        StudentProfile::factory()->for($person)->create(['academic_status' => 'ACTIVE']);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
        ]));

        $res->assertStatus(201);
        $event = AccessEvent::where('uuid', $res->json('data.uuid'))->firstOrFail();

        $this->assertDatabaseCount('access_decisions', 1);
        $decision = AccessDecision::where('event_id', $event->id)->firstOrFail();
        $this->assertSame(AccessDecisionOutcome::ALLOW, $decision->decision);
        $this->assertSame('STUDENT_ACTIVE', $decision->reason_code);
        $this->assertSame(DecisionSource::POLICY, $decision->decision_source);
        $this->assertSame($person->id, $decision->person_id);
        $this->assertNull($decision->decided_by_user_id);
    }

    public function test_an_expelled_students_match_produces_a_deny_decision(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $person = Person::factory()->create();
        StudentProfile::factory()->for($person)->create(['academic_status' => 'EXPELLED']);

        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
        ]))->assertStatus(201);

        $decision = AccessDecision::where('person_id', $person->id)->firstOrFail();
        $this->assertSame(AccessDecisionOutcome::DENY, $decision->decision);
        $this->assertSame('STUDENT_EXPELLED', $decision->reason_code);
    }

    public function test_an_unknown_classification_produces_an_unknown_decision(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'classification' => 'UNKNOWN',
            'candidate_person_uuid' => null,
            'similarity' => null,
            'distance' => null,
        ]))->assertStatus(201);

        $decision = AccessDecision::first();
        $this->assertSame(AccessDecisionOutcome::UNKNOWN, $decision->decision);
        $this->assertSame('RECOGNITION_UNKNOWN', $decision->reason_code);
        $this->assertNull($decision->person_id);
    }

    public function test_a_person_with_no_student_profile_fails_closed_to_review(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $person = Person::factory()->create(); // no StudentProfile

        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
        ]))->assertStatus(201);

        $decision = AccessDecision::where('person_id', $person->id)->firstOrFail();
        $this->assertSame(AccessDecisionOutcome::REVIEW, $decision->decision);
        $this->assertSame('NO_MATCHING_POLICY', $decision->reason_code);
    }

    public function test_an_idempotent_retry_does_not_create_a_second_decision(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $payload = $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => null,
        ]);

        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $payload)->assertStatus(201);
        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $payload)->assertStatus(200);

        $this->assertDatabaseCount('access_events', 1);
        $this->assertDatabaseCount('access_decisions', 1);
    }

    public function test_a_cooldown_suppressed_event_does_not_create_a_second_decision(): void
    {
        config(['event.semantic_cooldown_seconds' => 30]);
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $person = Person::factory()->create();
        StudentProfile::factory()->for($person)->create(['academic_status' => 'ACTIVE']);

        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
            'track_id' => 'track-1',
        ]))->assertStatus(201);

        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
            'track_id' => 'track-2',
        ]))->assertStatus(200);

        $this->assertDatabaseCount('access_events', 1);
        $this->assertDatabaseCount('access_decisions', 1);
    }

    public function test_decision_response_is_never_exposed_by_the_ingestion_endpoint(): void
    {
        // Event != Decision extends to the response shape too — the AI
        // node's ack for its own submission never carries the decision.
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
        ]));

        $res->assertStatus(201);
        $data = $res->json('data');
        foreach (['decision', 'access_decision', 'reason_code', 'decision_source'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }
    }
}
