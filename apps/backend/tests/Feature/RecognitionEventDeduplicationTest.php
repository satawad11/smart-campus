<?php

namespace Tests\Feature;

use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Person;
use App\Services\Camera\AiNodeCredentialService;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EVT-005 — semantic cooldown / deduplication.
 *
 * Distinct from EVT-004's exact-event_uuid idempotency: these tests send
 * genuinely different event_uuids (as a track re-acquisition on the
 * AI-node side would) that still describe the same real-world occurrence
 * within the cooldown window.
 */
class RecognitionEventDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EventTypeSeeder::class);
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

    public function test_a_second_match_for_the_same_camera_and_person_within_the_window_is_suppressed(): void
    {
        config(['event.semantic_cooldown_seconds' => 30]);
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $person = Person::factory()->create();

        $first = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
            'track_id' => 'track-1',
        ]));
        $first->assertStatus(201);
        $firstUuid = $first->json('data.uuid');

        // A different track_id/event_uuid — as a lost-and-reacquired track
        // on the AI node would produce — but the same person at the same
        // camera, moments later.
        $second = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
            'track_id' => 'track-2',
        ]));

        $second->assertStatus(200);
        $second->assertJsonPath('data.uuid', $firstUuid);
        $this->assertDatabaseCount('access_events', 1);
    }

    public function test_a_match_for_the_same_person_at_a_different_camera_is_not_suppressed(): void
    {
        config(['event.semantic_cooldown_seconds' => 30]);
        $node = AiNode::factory()->create();
        $cameraA = Camera::factory()->create(['ai_node_id' => $node->id]);
        $cameraB = Camera::factory()->create(['ai_node_id' => $node->id]);
        $person = Person::factory()->create();

        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $cameraA->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
        ]))->assertStatus(201);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $cameraB->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
        ]));

        $res->assertStatus(201);
        $this->assertDatabaseCount('access_events', 2);
    }

    public function test_a_match_after_the_cooldown_window_elapses_is_not_suppressed(): void
    {
        config(['event.semantic_cooldown_seconds' => 30]);
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $person = Person::factory()->create();

        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
        ]))->assertStatus(201);

        $this->travel(31)->seconds();

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
        ]));

        $res->assertStatus(201);
        $this->assertDatabaseCount('access_events', 2);
    }

    public function test_a_different_person_at_the_same_camera_is_not_suppressed(): void
    {
        config(['event.semantic_cooldown_seconds' => 30]);
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $personA = Person::factory()->create();
        $personB = Person::factory()->create();

        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $personA->uuid,
        ]))->assertStatus(201);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $personB->uuid,
        ]));

        $res->assertStatus(201);
        $this->assertDatabaseCount('access_events', 2);
    }

    public function test_a_second_unknown_event_for_the_same_camera_and_track_within_the_window_is_suppressed(): void
    {
        config(['event.unknown_cooldown_seconds' => 60]);
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $first = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'classification' => 'UNKNOWN',
            'candidate_person_uuid' => null,
            'similarity' => null,
            'distance' => null,
            'track_id' => 'track-1',
        ]));
        $first->assertStatus(201);
        $firstUuid = $first->json('data.uuid');

        $second = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'classification' => 'UNKNOWN',
            'candidate_person_uuid' => null,
            'similarity' => null,
            'distance' => null,
            'track_id' => 'track-1',
        ]));

        $second->assertStatus(200);
        $second->assertJsonPath('data.uuid', $firstUuid);
        $this->assertDatabaseCount('access_events', 1);
    }

    public function test_an_unknown_event_for_a_different_track_is_not_suppressed(): void
    {
        config(['event.unknown_cooldown_seconds' => 60]);
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);

        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'classification' => 'UNKNOWN',
            'candidate_person_uuid' => null,
            'similarity' => null,
            'distance' => null,
            'track_id' => 'track-1',
        ]))->assertStatus(201);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'classification' => 'UNKNOWN',
            'candidate_person_uuid' => null,
            'similarity' => null,
            'distance' => null,
            'track_id' => 'track-2',
        ]));

        $res->assertStatus(201);
        $this->assertDatabaseCount('access_events', 2);
    }

    public function test_cooldown_disabled_when_configured_to_zero(): void
    {
        config(['event.semantic_cooldown_seconds' => 0]);
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $person = Person::factory()->create();

        $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
        ]))->assertStatus(201);

        $res = $this->withHeaders($this->bearerFor($node))->postJson('/internal/v1/events/recognition', $this->basePayload([
            'camera_uuid' => $camera->uuid,
            'ai_node_uuid' => $node->uuid,
            'candidate_person_uuid' => $person->uuid,
        ]));

        $res->assertStatus(201);
        $this->assertDatabaseCount('access_events', 2);
    }
}
