<?php

namespace Tests\Feature;

use App\Events\AccessEventBroadcast;
use App\Exceptions\ApiException;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Services\Camera\AiNodeCredentialService;
use App\Services\Event\RecognitionEventIngestionService;
use Database\Seeders\AccessPolicySeeder;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V1 audit A-07/A-08: ingestion must survive a lost unique-index race and a
 * Reverb outage without losing or duplicating the event.
 */
class IngestionResilienceTest extends TestCase
{
    use RefreshDatabase;

    private AiNode $node;

    private Camera $camera;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, EventTypeSeeder::class, AccessPolicySeeder::class]);
        config(['event.semantic_cooldown_seconds' => 0, 'event.unknown_cooldown_seconds' => 0]);
        $this->node = AiNode::factory()->create();
        $this->camera = Camera::factory()->create(['ai_node_id' => $this->node->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event_uuid' => (string) Str::uuid(),
            'camera_uuid' => $this->camera->uuid,
            'ai_node_uuid' => $this->node->uuid,
            'track_id' => 'track-1',
            'occurred_at' => now()->toISOString(),
            'classification' => 'UNKNOWN',
            'model_code' => 'arcface-r50-v1',
            'model_version' => '1.0.0',
            'rejected_locally' => false,
        ], $overrides);
    }

    public function test_reverb_outage_does_not_lose_the_event_or_its_decision(): void
    {
        // A real Pusher-protocol broadcaster pointed at a closed port: broadcast() throws
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'k',
            'broadcasting.connections.reverb.secret' => 's',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($this->node, null, null);
        $payload = $this->payload();

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/internal/v1/events/recognition', $payload)
            ->assertStatus(201);

        $event = AccessEvent::where('uuid', $payload['event_uuid'])->first();
        $this->assertNotNull($event, 'event persisted despite broadcast failure');
        $this->assertSame(1, AccessDecision::where('event_id', $event->id)->count());
    }

    public function test_broadcast_happens_only_after_commit(): void
    {
        $committedAtBroadcast = null;
        Event::listen(AccessEventBroadcast::class, function ($broadcast) use (&$committedAtBroadcast) {
            $committedAtBroadcast = DB::transactionLevel();
        });
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($this->node, null, null);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/internal/v1/events/recognition', $this->payload())
            ->assertStatus(201);

        // RefreshDatabase wraps each test in one transaction; the ingestion
        // transaction itself must already be closed when the event fires.
        $this->assertSame(1, $committedAtBroadcast);
    }

    public function test_lost_unique_race_is_answered_like_an_idempotent_retry(): void
    {
        $payload = $this->payload();
        $service = app(RecognitionEventIngestionService::class);
        $first = $service->ingest($this->node, $payload);

        // Simulate the concurrent loser: the existence check passes (no row
        // visible yet in its snapshot) and the insert hits the unique index.
        AccessEvent::creating(function (AccessEvent $event) use ($payload) {
            if ($event->uuid === $payload['event_uuid']) {
                throw new UniqueConstraintViolationException('pgsql', 'insert', [], new \Exception('duplicate key'));
            }
        });
        $hidden = AccessEvent::where('uuid', $payload['event_uuid'])->first();
        $hidden->uuid = (string) Str::uuid();
        $hidden->saveQuietly();

        try {
            $service->ingest($this->node, $payload);
            $this->fail('expected the simulated unique violation to be translated');
        } catch (ApiException $e) {
            // Row is not found under the original uuid in this simulation, so the
            // service must answer the documented 409 rather than a 500.
            $this->assertSame(409, $e->status);
        }

        $hidden->uuid = $payload['event_uuid'];
        $hidden->saveQuietly();
        AccessEvent::flushEventListeners();
        $this->assertSame($first->id, $service->ingest($this->node, $payload)->id);
    }
}
