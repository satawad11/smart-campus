<?php

namespace Tests\Feature;

use App\Events\PlateEventBroadcast;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Vehicle;
use App\Services\Camera\AiNodeCredentialService;
use App\Services\System\SystemSettingService;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * VEH-010 (ACR-VEH-01): /internal/v1/events/plate — gates, normalisation, registry lookup,
 * event types, idempotency, cooldown, broadcast, and "never a decision".
 */
class PlateEventIngestionTest extends TestCase
{
    use RefreshDatabase;

    private AiNode $node;

    private Camera $camera;

    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EventTypeSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        config(['features.locked_kill_switches.alpr' => null, 'alpr.ocr_min_confidence' => 0.9, 'alpr.plate_cooldown_seconds' => 30]);
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.alpr' => true]);

        $this->node = AiNode::factory()->create();
        $this->camera = Camera::factory()->create(['ai_node_id' => $this->node->id, 'plate_recognition_enabled' => true]);
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($this->node, null, null);
        $this->auth = ['Authorization' => "Bearer {$token}"];
        Event::fake([PlateEventBroadcast::class]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event_uuid' => (string) Str::uuid(),
            'camera_uuid' => $this->camera->uuid,
            'ai_node_uuid' => $this->node->uuid,
            'track_id' => 'veh-1',
            'occurred_at' => now()->toIso8601String(),
            'plate_text' => '1กข 1234',
            'province_text' => 'กรุงเทพมหานคร',
            'ocr_score' => 0.97,
            'detection_score' => 0.88,
            'frames_voted' => 5,
            'model_code' => 'lpd-yunet+ppocr-th',
            'model_version' => 'eval',
        ], $overrides);
    }

    private function send(array $payload)
    {
        return $this->withHeaders($this->auth)->postJson('/internal/v1/events/plate', $payload);
    }

    public function test_requires_node_token(): void
    {
        $this->postJson('/internal/v1/events/plate', $this->payload())->assertStatus(401);
    }

    public function test_registered_plate_becomes_plate_recognized_without_person_or_decision(): void
    {
        $vehicle = Vehicle::factory()->create(['plate_number' => '1กข1234', 'plate_province' => 'TH-10', 'status' => 'BLOCKED']);

        $res = $this->send($this->payload(['plate_text' => '๑กข-๑๒๓๔', 'province_text' => 'กทม']))->assertCreated();

        $res->assertJsonPath('data.event_type', 'PLATE_RECOGNIZED')
            ->assertJsonPath('data.plate_number', '1กข1234')
            ->assertJsonPath('data.plate_province', 'TH-10')
            ->assertJsonPath('data.vehicle_uuid', $vehicle->uuid);

        $event = AccessEvent::where('uuid', $res->json('data.event_uuid'))->sole();
        $this->assertNull($event->person_id);
        $this->assertSame($this->camera->site_id, $event->site_id);
        $this->assertSame('BLOCKED', $event->metadata['plate']['vehicle_status']);
        $this->assertSame('1กข 1234', $event->metadata['plate']['plate_display']);
        $this->assertSame(0, AccessDecision::count(), 'A plate read must never create an access decision');
        Event::assertDispatched(PlateEventBroadcast::class, fn ($b) => $b->event->is($event));
    }

    public function test_unregistered_plate_is_plate_unknown(): void
    {
        $this->send($this->payload())->assertCreated()
            ->assertJsonPath('data.event_type', 'PLATE_UNKNOWN')
            ->assertJsonPath('data.vehicle_uuid', null);
    }

    public function test_low_score_or_unreadable_text_is_low_confidence_and_not_looked_up(): void
    {
        Vehicle::factory()->create(['plate_number' => '1กข1234', 'plate_province' => 'TH-10']);

        $this->send($this->payload(['ocr_score' => 0.5]))->assertCreated()
            ->assertJsonPath('data.event_type', 'PLATE_LOW_CONFIDENCE')
            ->assertJsonPath('data.vehicle_uuid', null);

        $this->send($this->payload(['plate_text' => 'AB?12', 'track_id' => 'veh-2']))->assertCreated()
            ->assertJsonPath('data.event_type', 'PLATE_LOW_CONFIDENCE')
            ->assertJsonPath('data.plate_number', null);
    }

    public function test_unreadable_province_matches_only_a_unique_plate(): void
    {
        $only = Vehicle::factory()->create(['plate_number' => '2กข2222', 'plate_province' => 'TH-50']);
        Vehicle::factory()->create(['plate_number' => '3กข3333', 'plate_province' => 'TH-10']);
        Vehicle::factory()->create(['plate_number' => '3กข3333', 'plate_province' => 'TH-20']);

        $this->send($this->payload(['plate_text' => '2กข2222', 'province_text' => null, 'track_id' => 'a']))
            ->assertJsonPath('data.vehicle_uuid', $only->uuid);
        $this->send($this->payload(['plate_text' => '3กข3333', 'province_text' => '???', 'track_id' => 'b']))
            ->assertJsonPath('data.event_type', 'PLATE_UNKNOWN');
    }

    public function test_alpr_off_or_camera_switch_off_is_rejected(): void
    {
        $this->camera->update(['plate_recognition_enabled' => false]);
        $this->send($this->payload())->assertStatus(422)->assertJsonPath('error.code', 'PLATE_RECOGNITION_DISABLED');

        $this->camera->update(['plate_recognition_enabled' => true]);
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.alpr' => false]);
        $this->send($this->payload())->assertStatus(422)->assertJsonPath('error.code', 'ALPR_DISABLED');

        $this->assertSame(0, AccessEvent::count());
    }

    public function test_node_identity_and_camera_ownership_are_enforced(): void
    {
        $other = Camera::factory()->create(['plate_recognition_enabled' => true]);

        $this->send($this->payload(['ai_node_uuid' => (string) Str::uuid()]))
            ->assertStatus(422)->assertJsonPath('error.code', 'AI_NODE_IDENTITY_MISMATCH');
        $this->send($this->payload(['camera_uuid' => $other->uuid]))
            ->assertStatus(422)->assertJsonPath('error.code', 'CAMERA_NOT_ASSIGNED_TO_NODE');
    }

    public function test_node_cannot_supply_type_vehicle_person_or_decision(): void
    {
        $this->send($this->payload([
            'event_type' => 'PLATE_RECOGNIZED',
            'vehicle_uuid' => (string) Str::uuid(),
            'person_uuid' => (string) Str::uuid(),
            'decision' => 'ALLOW',
        ]))->assertStatus(422)->assertJsonValidationErrors(['event_type', 'vehicle_uuid', 'person_uuid', 'decision'], 'error.fields');
    }

    public function test_same_event_uuid_retry_is_idempotent_and_conflicting_reuse_fails(): void
    {
        $payload = $this->payload();

        $this->send($payload)->assertCreated();
        $this->send($payload)->assertOk()->assertJsonPath('message', 'Plate event already recorded');
        $this->assertSame(1, AccessEvent::count());

        $this->send(array_merge($payload, ['track_id' => 'someone-else']))
            ->assertStatus(409)->assertJsonPath('error.code', 'EVENT_ALREADY_EXISTS');
    }

    public function test_same_plate_at_same_camera_within_cooldown_is_one_pass(): void
    {
        $this->send($this->payload(['track_id' => 't1']))->assertCreated();
        $this->send($this->payload(['track_id' => 't2']))->assertOk();
        $this->send($this->payload(['track_id' => 't3', 'plate_text' => '9ฮฮ9999']))->assertCreated();

        $this->assertSame(2, AccessEvent::count());
        Event::assertDispatchedTimes(PlateEventBroadcast::class, 2);
    }
}
