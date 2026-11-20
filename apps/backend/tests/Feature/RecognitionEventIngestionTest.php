<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Person;
use App\Services\Camera\AiNodeCredentialService;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EVT-003/EVT-004 — internal (AI-node-facing)
 * POST /internal/v1/events/recognition recognition-event ingestion,
 * including event_uuid idempotency. Semantic cooldown/deduplication
 * (EVT-005) has its own test file, RecognitionEventDeduplicationTest.
 */
class RecognitionEventIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // access_events.event_type FKs to event_types.code — the codes
        // this endpoint maps into (FACE_RECOGNIZED/UNKNOWN_PERSON/
        // LIVENESS_FAILED) must exist first.
        $this->seed(EventTypeSeeder::class);
        // Isolate these tests from EVT-005's semantic cooldown — several
        // send multiple events for the same camera/person in quick
        // succession on purpose, which the default cooldown would
        // otherwise suppress into one row.
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

    public function test_requires_ai_node_token(): void
    {
        $this->postJson('/internal/v1/events/recognition', $this->basePayload())->assertStatus(401);
    }

    public function test_ingests_a_match_event_and_resolves_the_person(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $person = Person::factory()->create();

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'classification' => 'MATCH',
            'candidate_person_uuid' => $person->uuid,
        ]));

        $res->assertStatus(201);
        $res->assertJsonPath('data.event_type', 'FACE_RECOGNIZED');
        $res->assertJsonPath('data.person.uuid', $person->uuid);
        $res->assertJsonPath('data.camera.uuid', $camera->uuid);

        $this->assertDatabaseHas('access_events', [
            'person_id' => $person->id,
            'camera_id' => $camera->id,
            'site_id' => $camera->site_id,
            'zone_id' => $camera->zone_id,
            'ai_node_id' => $node->id,
            'event_type' => 'FACE_RECOGNIZED',
        ]);
    }

    public function test_unknown_classification_maps_to_unknown_person_event_type_with_no_person(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'classification' => 'UNKNOWN',
            'candidate_person_uuid' => null,
            'similarity' => null,
            'distance' => null,
        ]));

        $res->assertStatus(201);
        $res->assertJsonPath('data.event_type', 'UNKNOWN_PERSON');
        $res->assertJsonPath('data.person', null);
    }

    public function test_unknown_keeps_real_score_but_never_candidate_identity(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'classification' => 'UNKNOWN',
            'candidate_person_uuid' => null,
            'similarity' => 0.41,
            'distance' => 0.59,
        ]));

        $res->assertStatus(201)
            ->assertJsonPath('data.person', null)
            ->assertJsonPath('data.face_score', 0.41)
            ->assertJsonPath('data.review_candidate', null);
    }

    public function test_review_exposes_safe_candidate_but_does_not_assign_person(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $candidate = Person::factory()->create([
            'display_name' => 'Review Candidate',
            'person_code' => 'P-REVIEW',
        ]);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'classification' => 'REVIEW',
            'candidate_person_uuid' => $candidate->uuid,
            'similarity' => 0.72,
            'distance' => 0.28,
        ]));

        $res->assertStatus(201)
            ->assertJsonPath('data.person', null)
            ->assertJsonPath('data.face_score', 0.72)
            ->assertJsonPath('data.review_candidate.uuid', $candidate->uuid)
            ->assertJsonPath('data.review_candidate.name', 'Review Candidate');

        $event = AccessEvent::firstOrFail();
        $this->assertNull($event->person_id);
        $this->assertSame($candidate->uuid, $event->metadata['review_candidate']['uuid']);
        $this->assertNotSame('ALLOW', $event->latestDecision()->firstOrFail()->decision->value);
    }

    public function test_spoof_liveness_maps_to_liveness_failed_event_type_regardless_of_classification(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'classification' => 'UNKNOWN',
            'liveness_status' => 'SPOOF',
            'rejected_locally' => true,
            'reasons' => ['LIVENESS_FAILED'],
        ]));

        $res->assertStatus(201);
        $res->assertJsonPath('data.event_type', 'LIVENESS_FAILED');
    }

    public function test_a_match_without_a_candidate_person_uuid_still_ingests_with_no_person(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        // Defensive: classification=MATCH but no candidate — should never
        // happen from a well-behaved node, but must not crash ingestion.
        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'classification' => 'MATCH',
            'candidate_person_uuid' => null,
        ]));

        $res->assertStatus(201);
        $res->assertJsonPath('data.person', null);
    }

    public function test_rejects_a_node_reporting_an_event_for_a_camera_it_is_not_assigned_to(): void
    {
        $node = AiNode::factory()->create();
        $otherNode = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $otherNode->id]);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
        ]));

        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'CAMERA_NOT_ASSIGNED_TO_NODE');
        $this->assertDatabaseCount('access_events', 0);
    }

    public function test_rejects_a_mismatched_ai_node_uuid_in_the_body(): void
    {
        $node = AiNode::factory()->create();
        $otherNode = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $otherNode->uuid, // spoofed identity in the body
        ]));

        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'AI_NODE_IDENTITY_MISMATCH');
        $this->assertDatabaseCount('access_events', 0);
    }

    public function test_unknown_camera_uuid_returns_422(): void
    {
        $node = AiNode::factory()->create();

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => (string) Str::uuid(),
            'ai_node_uuid' => $node->uuid,
        ]));

        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'CAMERA_NOT_FOUND');
    }

    public function test_retrying_the_same_event_uuid_with_the_same_content_is_idempotent(): void
    {
        // EVT-004: CAM-012's offline buffer guarantees at-least-once
        // delivery, so a network-blip retry of the exact same event is
        // routine, not an error — it must return the original event
        // unchanged (200), never a second row.
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $eventUuid = (string) Str::uuid();

        $payload = $this->basePayload([
            'event_uuid' => $eventUuid,
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
        ]);

        $first = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $payload);
        $first->assertStatus(201);

        $retry = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $payload);
        $retry->assertStatus(200);
        $retry->assertJsonPath('data.uuid', $eventUuid);

        $this->assertDatabaseCount('access_events', 1);
    }

    public function test_reusing_an_event_uuid_for_a_genuinely_different_event_is_a_409_conflict(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $otherCamera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $eventUuid = (string) Str::uuid();

        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'event_uuid' => $eventUuid,
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'track_id' => 'track-1',
        ]))->assertStatus(201);

        // Same event_uuid, but a different camera/track — not the same
        // occurrence, so idempotency must not silently accept it.
        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'event_uuid' => $eventUuid,
            'camera_uuid' => $otherCamera->uuid,
            'ai_node_uuid' => $node->uuid,
            'track_id' => 'track-2',
        ]));

        $res->assertStatus(409);
        $res->assertJsonPath('error.code', 'EVENT_ALREADY_EXISTS');
        $this->assertDatabaseCount('access_events', 1);
    }

    public function test_prohibited_decision_fields_are_unconditionally_rejected(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'access_decision' => 'ALLOW',
            'decision' => 'ALLOW',
        ]));

        $res->assertStatus(422);
    }

    public function test_response_never_contains_an_access_decision_field(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
        ]));

        $res->assertStatus(201);
        $data = $res->json('data');
        foreach (['decision', 'access_decision', 'door_state', 'relay_triggered'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }
    }

    public function test_event_type_and_metadata_are_never_null_essential_fields_missing(): void
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
        ]))->assertStatus(201);

        $event = AccessEvent::firstOrFail();
        $this->assertSame('arcface-r50-v1', $event->metadata['model_code']);
        $this->assertSame('MATCH', $event->metadata['classification']);
        $this->assertNotNull($event->occurred_at);
        $this->assertNotNull($event->received_at);
    }
}
