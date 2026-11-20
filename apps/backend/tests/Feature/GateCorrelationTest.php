<?php

namespace Tests\Feature;

use App\Enums\GateCameraRole;
use App\Enums\GateMatchResult;
use App\Enums\LocationStatus;
use App\Events\AccessEventBroadcast;
use App\Events\PlateEventBroadcast;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Gate;
use App\Models\GateCamera;
use App\Models\GatePass;
use App\Models\Person;
use App\Models\Vehicle;
use App\Models\VehicleOwnershipHistory;
use App\Services\Camera\AiNodeCredentialService;
use App\Services\Gate\GateCorrelationService;
use App\Services\System\SystemSettingService;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GATE-004 (ACR-VEH-01 Phase 5 §7): face + plate correlation within the gate time window.
 */
class GateCorrelationTest extends TestCase
{
    use RefreshDatabase;

    private Gate $gate;

    private Camera $faceCamera;

    private Camera $plateCamera;

    private GateCorrelationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EventTypeSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        config(['features.locked_kill_switches.vehicle' => null]);
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.vehicle' => true]);
        $this->travelTo(now()->startOfMinute());

        $this->gate = Gate::factory()->create(['correlation_window_seconds' => 10]);
        $this->faceCamera = $this->linkCamera(GateCameraRole::FACE);
        $this->plateCamera = $this->linkCamera(GateCameraRole::PLATE);
        // Events below are received after the cameras were linked.
        $this->travel(1)->seconds();

        $this->service = app(GateCorrelationService::class);
    }

    private function linkCamera(GateCameraRole $role, ?Gate $gate = null): Camera
    {
        $gate ??= $this->gate;
        $camera = Camera::factory()->create(['site_id' => $gate->site_id, 'zone_id' => $gate->zone_id]);
        GateCamera::create(['gate_id' => $gate->id, 'camera_id' => $camera->id, 'role' => $role]);

        return $camera;
    }

    private function face(?Person $person, int $offsetSeconds = 0, ?Camera $camera = null): AccessEvent
    {
        $camera ??= $this->faceCamera;

        return AccessEvent::factory()->create([
            'site_id' => $camera->site_id,
            'zone_id' => $camera->zone_id,
            'camera_id' => $camera->id,
            'person_id' => $person?->id,
            'event_type' => $person !== null ? 'FACE_RECOGNIZED' : 'UNKNOWN_PERSON',
            'occurred_at' => now()->addSeconds($offsetSeconds),
        ]);
    }

    private function plate(?Vehicle $vehicle, int $offsetSeconds = 0, ?Camera $camera = null): AccessEvent
    {
        $camera ??= $this->plateCamera;

        return AccessEvent::factory()->create([
            'site_id' => $camera->site_id,
            'zone_id' => $camera->zone_id,
            'camera_id' => $camera->id,
            'event_type' => $vehicle !== null ? 'PLATE_RECOGNIZED' : 'PLATE_UNKNOWN',
            'occurred_at' => now()->addSeconds($offsetSeconds),
            'face_score' => null,
            'metadata' => ['plate' => ['plate_number' => $vehicle?->plate_number ?? '9ฮฮ9999', 'vehicle_uuid' => $vehicle?->uuid]],
        ]);
    }

    private function link(Person $person, Vehicle $vehicle, array $overrides = []): void
    {
        VehicleOwnershipHistory::factory()->create(array_merge([
            'person_id' => $person->id,
            'vehicle_id' => $vehicle->id,
            'effective_from' => now()->subYear(),
            'effective_to' => null,
        ], $overrides));
    }

    public function test_linked_person_and_vehicle_make_a_matched_pass_and_events_stay_untouched(): void
    {
        $person = Person::factory()->create();
        $vehicle = Vehicle::factory()->create();
        $this->link($person, $vehicle);

        $face = $this->face($person);
        $this->assertNull($this->service->onEventIngested($face), 'No partner yet: nothing is created');

        $plate = $this->plate($vehicle, 3);
        $before = [$face->fresh()->toArray(), $plate->fresh()->toArray()];
        $pass = $this->service->onEventIngested($plate);

        $this->assertNotNull($pass);
        $this->assertSame(GateMatchResult::MATCHED, $pass->match_result);
        $this->assertSame($face->id, $pass->face_event_id);
        $this->assertSame($plate->id, $pass->plate_event_id);
        $this->assertSame($person->id, $pass->person_id);
        $this->assertSame($vehicle->id, $pass->vehicle_id);
        $this->assertTrue($pass->occurred_at->eq($face->occurred_at), 'A pass happens at its earliest event');
        $this->assertSame($before, [$face->fresh()->toArray(), $plate->fresh()->toArray()]);
        // GATE-007: the pass's decision is a separate AccessDecision row; the events stay as they were.
        $this->assertSame(1, AccessDecision::where('gate_pass_id', $pass->id)->count());
    }

    public function test_plate_first_then_face_also_pairs(): void
    {
        $person = Person::factory()->create();
        $vehicle = Vehicle::factory()->create();
        $this->link($person, $vehicle);

        $this->assertNull($this->service->onEventIngested($this->plate($vehicle)));
        $pass = $this->service->onEventIngested($this->face($person, 2));

        $this->assertSame(GateMatchResult::MATCHED, $pass->match_result);
    }

    public function test_both_known_but_not_linked_is_a_mismatch(): void
    {
        $person = Person::factory()->create();
        $vehicle = Vehicle::factory()->create();
        $this->link(Person::factory()->create(), $vehicle);

        $this->service->onEventIngested($this->face($person));
        $pass = $this->service->onEventIngested($this->plate($vehicle, 1));

        $this->assertSame(GateMatchResult::MISMATCH, $pass->match_result);
        $this->assertSame($person->id, $pass->person_id);
        $this->assertSame($vehicle->id, $pass->vehicle_id);
    }

    public function test_an_ended_link_does_not_match(): void
    {
        $person = Person::factory()->create();
        $vehicle = Vehicle::factory()->create();
        $this->link($person, $vehicle, ['effective_to' => now()->subDay()]);

        $this->service->onEventIngested($this->face($person));
        $pass = $this->service->onEventIngested($this->plate($vehicle, 1));

        $this->assertSame(GateMatchResult::MISMATCH, $pass->match_result);
    }

    public function test_unrecognised_face_or_plate_is_unknown_and_keeps_what_is_known(): void
    {
        $vehicle = Vehicle::factory()->create();
        $this->service->onEventIngested($this->face(null));
        $first = $this->service->onEventIngested($this->plate($vehicle, 1));

        $person = Person::factory()->create();
        $this->service->onEventIngested($this->face($person, 30));
        $second = $this->service->onEventIngested($this->plate(null, 31));

        $this->assertSame(GateMatchResult::UNKNOWN, $first->match_result);
        $this->assertNull($first->person_id);
        $this->assertSame($vehicle->id, $first->vehicle_id);
        $this->assertSame(GateMatchResult::UNKNOWN, $second->match_result);
        $this->assertSame($person->id, $second->person_id);
        $this->assertNull($second->vehicle_id);
    }

    public function test_events_outside_the_window_are_not_paired_and_the_sweep_closes_them_alone(): void
    {
        $person = Person::factory()->create();
        $vehicle = Vehicle::factory()->create();
        $face = $this->face($person);
        $this->service->onEventIngested($face);
        $plate = $this->plate($vehicle, 11);
        $this->assertNull($this->service->onEventIngested($plate), '11 s apart, window is 10 s');

        // Not yet: the window plus grace has not passed since they were received.
        $this->assertSame(0, $this->service->closeExpired());

        $this->travel(10 + GateCorrelationService::CLOSE_GRACE_SECONDS + 1)->seconds();
        $this->assertSame(2, $this->service->closeExpired());

        $this->assertSame(GateMatchResult::FACE_ONLY, GatePass::where('face_event_id', $face->id)->value('match_result'));
        $this->assertSame(GateMatchResult::PLATE_ONLY, GatePass::where('plate_event_id', $plate->id)->value('match_result'));
        $this->assertSame($person->id, GatePass::where('face_event_id', $face->id)->value('person_id'));
        $this->assertSame($vehicle->id, GatePass::where('plate_event_id', $plate->id)->value('vehicle_id'));

        // Nothing more on a second run.
        $this->assertSame(0, $this->service->closeExpired());
        $this->assertSame(2, GatePass::count());
    }

    public function test_the_sweep_pairs_events_missed_at_ingest_time(): void
    {
        $person = Person::factory()->create();
        $vehicle = Vehicle::factory()->create();
        $this->link($person, $vehicle);
        // Ingest-time correlation never ran (e.g. it failed).
        $this->face($person);
        $this->plate($vehicle, 2);

        $this->travel(30)->seconds();
        $this->assertSame(1, $this->service->closeExpired());
        $this->assertSame(GateMatchResult::MATCHED, GatePass::sole()->match_result);
    }

    public function test_closest_candidate_in_the_window_wins(): void
    {
        $far = $this->face(Person::factory()->create(), -8);
        $near = $this->face(Person::factory()->create(), -1);

        $pass = $this->service->onEventIngested($this->plate(Vehicle::factory()->create()));

        $this->assertSame($near->id, $pass->face_event_id);
        $this->assertFalse(GatePass::where('face_event_id', $far->id)->exists());
    }

    public function test_an_event_is_used_by_one_pass_only(): void
    {
        $face = $this->face(Person::factory()->create());
        $plate = $this->plate(Vehicle::factory()->create(), 1);

        $this->service->onEventIngested($plate);
        $this->assertNull($this->service->onEventIngested($plate), 'Second trigger for the same event');
        $this->assertNull($this->service->onEventIngested($face), 'Partner already paired');

        $second = $this->service->onEventIngested($this->plate(Vehicle::factory()->create(), 2));
        $this->assertNull($second, 'The only face event in the window is already taken');
        $this->assertSame(1, GatePass::count());
    }

    public function test_one_camera_with_role_both_pairs_its_own_face_and_plate_events(): void
    {
        $gate = Gate::factory()->create();
        $camera = $this->linkCamera(GateCameraRole::BOTH, $gate);
        $this->travel(1)->seconds();

        $this->service->onEventIngested($this->face(Person::factory()->create(), 0, $camera));
        $pass = $this->service->onEventIngested($this->plate(Vehicle::factory()->create(), 1, $camera));

        $this->assertSame($gate->id, $pass->gate_id);
    }

    public function test_events_that_do_not_belong_to_an_active_gate_are_ignored(): void
    {
        // Face event from the PLATE-role camera: not a gate face event.
        $this->service->onEventIngested($this->face(Person::factory()->create(), 0, $this->plateCamera));
        // Camera not linked to any gate.
        $loose = Camera::factory()->create(['site_id' => $this->gate->site_id, 'zone_id' => $this->gate->zone_id]);
        $this->service->onEventIngested($this->face(Person::factory()->create(), 0, $loose));

        // Inactive gate.
        $inactive = Gate::factory()->create(['status' => LocationStatus::INACTIVE]);
        $faceCam = $this->linkCamera(GateCameraRole::FACE, $inactive);
        $plateCam = $this->linkCamera(GateCameraRole::PLATE, $inactive);
        $this->travel(1)->seconds();
        $this->service->onEventIngested($this->face(Person::factory()->create(), 0, $faceCam));
        $this->assertNull($this->service->onEventIngested($this->plate(Vehicle::factory()->create(), 1, $plateCam)));

        $this->travel(60)->seconds();
        $this->assertSame(0, $this->service->closeExpired());
        $this->assertSame(0, GatePass::count());
    }

    public function test_events_received_before_the_camera_was_linked_or_beyond_the_lookback_are_ignored(): void
    {
        $gate = Gate::factory()->create();
        $camera = Camera::factory()->create(['site_id' => $gate->site_id, 'zone_id' => $gate->zone_id]);
        $before = $this->face(Person::factory()->create(), 0, $camera);
        $this->travel(1)->seconds();
        GateCamera::create(['gate_id' => $gate->id, 'camera_id' => $camera->id, 'role' => GateCameraRole::FACE]);

        $old = $this->face(Person::factory()->create());
        $this->travel(GateCorrelationService::LOOKBACK_MINUTES + 1)->minutes();

        $this->assertSame(0, $this->service->closeExpired());
        $this->assertFalse(GatePass::whereIn('face_event_id', [$before->id, $old->id])->exists());
    }

    public function test_nothing_happens_while_feature_vehicle_is_off(): void
    {
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.vehicle' => false]);

        $this->service->onEventIngested($this->face(Person::factory()->create()));
        $this->assertNull($this->service->onEventIngested($this->plate(Vehicle::factory()->create(), 1)));
        $this->travel(60)->seconds();
        $this->assertSame(0, $this->service->closeExpired());
        $this->artisan('gates:correlate')->assertSuccessful();

        $this->assertSame(0, GatePass::count());
    }

    public function test_command_closes_lone_events(): void
    {
        $this->face(Person::factory()->create());
        $this->travel(30)->seconds();

        $this->artisan('gates:correlate')->expectsOutputToContain('Created 1 gate pass(es).')->assertSuccessful();
        $this->assertSame(GateMatchResult::FACE_ONLY, GatePass::sole()->match_result);
    }

    public function test_ingestion_endpoints_pair_face_and_plate_into_one_pass(): void
    {
        Event::fake([AccessEventBroadcast::class, PlateEventBroadcast::class]);
        config(['features.locked_kill_switches.alpr' => null, 'alpr.ocr_min_confidence' => 0.9]);
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.alpr' => true]);

        $node = AiNode::factory()->create();
        $this->faceCamera->update(['ai_node_id' => $node->id]);
        $this->plateCamera->update(['ai_node_id' => $node->id, 'plate_recognition_enabled' => true]);
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);
        $auth = ['Authorization' => "Bearer {$token}"];

        $person = Person::factory()->create();
        $vehicle = Vehicle::factory()->create(['plate_number' => '1กข1234', 'plate_province' => 'TH-10']);
        $this->link($person, $vehicle);

        $this->withHeaders($auth)->postJson('/internal/v1/events/recognition', [
            'event_uuid' => (string) Str::uuid(),
            'camera_uuid' => $this->faceCamera->uuid,
            'ai_node_uuid' => $node->uuid,
            'track_id' => 'face-1',
            'occurred_at' => now()->toIso8601String(),
            'classification' => 'MATCH',
            'candidate_person_uuid' => $person->uuid,
            'similarity' => 0.95,
            'distance' => 0.05,
            'model_code' => 'arcface-r50-v1',
            'model_version' => '1.0.0',
            'quality_score' => 0.9,
            'liveness_status' => 'REAL',
            'rejected_locally' => false,
            'reasons' => [],
        ])->assertSuccessful();

        $this->withHeaders($auth)->postJson('/internal/v1/events/plate', [
            'event_uuid' => (string) Str::uuid(),
            'camera_uuid' => $this->plateCamera->uuid,
            'ai_node_uuid' => $node->uuid,
            'track_id' => 'veh-1',
            'occurred_at' => now()->addSeconds(2)->toIso8601String(),
            'plate_text' => '1กข 1234',
            'province_text' => 'กรุงเทพมหานคร',
            'ocr_score' => 0.97,
            'detection_score' => 0.88,
            'frames_voted' => 5,
            'model_code' => 'lpd-yunet+ppocr-th',
            'model_version' => 'eval',
        ])->assertSuccessful();

        $pass = GatePass::sole();
        $this->assertSame(GateMatchResult::MATCHED, $pass->match_result);
        $this->assertSame($person->id, $pass->person_id);
        $this->assertSame($vehicle->id, $pass->vehicle_id);
        // The face event's own decision, then the gate decision for the pass (GATE-007).
        $this->assertSame(2, AccessDecision::count());
        $this->assertSame(1, AccessDecision::where('gate_pass_id', $pass->id)->count());
    }
}
