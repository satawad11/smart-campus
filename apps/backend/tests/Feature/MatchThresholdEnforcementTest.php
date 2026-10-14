<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Person;
use App\Services\Camera\AiNodeCredentialService;
use Database\Seeders\AccessPolicySeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V1 audit A-18 (decision 2026-10-14): the backend re-checks a node-asserted
 * MATCH against the approved FACE-021 MATCH threshold (0.80) and ingests a
 * MATCH without sufficient similarity as REVIEW, never as an identified person.
 */
class MatchThresholdEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private AiNode $node;

    private Camera $camera;

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([EventTypeSeeder::class, AccessPolicySeeder::class]);
        config(['event.semantic_cooldown_seconds' => 0, 'event.unknown_cooldown_seconds' => 0]);
        $this->node = AiNode::factory()->create();
        $this->camera = Camera::factory()->create(['ai_node_id' => $this->node->id]);
        $this->person = Person::factory()->create();
    }

    private function ingest(array $overrides)
    {
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($this->node, null, null);

        return $this->withHeaders(['Authorization' => "Bearer {$token}"])->postJson('/internal/v1/events/recognition', array_merge([
            'event_uuid' => (string) Str::uuid(),
            'camera_uuid' => $this->camera->uuid,
            'ai_node_uuid' => $this->node->uuid,
            'track_id' => 'track-'.Str::random(6),
            'occurred_at' => now()->toISOString(),
            'classification' => 'MATCH',
            'candidate_person_uuid' => $this->person->uuid,
            'similarity' => 0.95,
            'distance' => 0.05,
            'model_code' => 'm',
            'model_version' => '1',
            'quality_score' => 0.9,
            'liveness_status' => 'REAL',
            'rejected_locally' => false,
            'reasons' => [],
        ], $overrides));
    }

    public function test_match_at_or_above_the_threshold_identifies_the_person(): void
    {
        foreach ([0.80, 0.95] as $similarity) {
            $this->ingest(['similarity' => $similarity])->assertStatus(201)->assertJsonPath('data.person.uuid', $this->person->uuid);
        }
        $this->assertSame(0, AccessEvent::whereJsonContains('metadata->reasons', 'SERVER_MATCH_THRESHOLD_NOT_MET')->count());
    }

    public function test_match_below_the_threshold_is_ingested_as_review_without_a_person(): void
    {
        $res = $this->ingest(['similarity' => 0.65]);

        $res->assertStatus(201)->assertJsonPath('data.person', null);
        $event = AccessEvent::sole();
        $this->assertNull($event->person_id);
        $this->assertSame('REVIEW', $event->metadata['classification']);
        $this->assertSame('MATCH', $event->metadata['server_reclassified_from']);
        $this->assertContains('SERVER_MATCH_THRESHOLD_NOT_MET', $event->metadata['reasons']);
    }

    public function test_match_without_a_similarity_is_not_trusted(): void
    {
        $this->ingest(['similarity' => null])->assertStatus(201)->assertJsonPath('data.person', null);

        $this->assertSame('REVIEW', AccessEvent::sole()->metadata['classification']);
    }

    public function test_threshold_follows_the_approved_configuration(): void
    {
        config(['face.recognition.match_threshold' => 0.90]);

        $this->ingest(['similarity' => 0.85])->assertStatus(201)->assertJsonPath('data.person', null);
    }

    public function test_other_classifications_are_untouched(): void
    {
        $this->ingest(['classification' => 'UNKNOWN', 'candidate_person_uuid' => null, 'similarity' => 0.2])->assertStatus(201);

        $event = AccessEvent::sole();
        $this->assertSame('UNKNOWN', $event->metadata['classification']);
        $this->assertNull($event->metadata['server_reclassified_from']);
    }
}
