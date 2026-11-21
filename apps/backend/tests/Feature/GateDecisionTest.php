<?php

namespace Tests\Feature;

use App\Enums\AccessDecisionOutcome;
use App\Enums\DecisionSource;
use App\Enums\GateCameraRole;
use App\Enums\GateMatchResult;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Events\AccessEventBroadcast;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Gate;
use App\Models\GateCamera;
use App\Models\GatePass;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Vehicle;
use App\Models\VehicleOwnershipHistory;
use App\Services\Camera\AiNodeCredentialService;
use App\Services\Gate\GateCorrelationService;
use App\Services\Gate\GateDecisionService;
use App\Services\System\SystemSettingService;
use Database\Seeders\AccessPolicySeeder;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GATE-007 (ACR-VEH-01 Phase 5 item 8): each Smart Gate pass gets one AccessDecision on its primary
 * event, pushed to the Live Monitor, alerted once and reviewable. Decision only, no barrier (D5).
 */
class GateDecisionTest extends TestCase
{
    use RefreshDatabase;

    private Gate $gate;

    private Camera $faceCamera;

    private Camera $plateCamera;

    private GateCorrelationService $correlation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EventTypeSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(AccessPolicySeeder::class);
        config(['features.locked_kill_switches.vehicle' => null]);
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.vehicle' => true]);
        $this->travelTo(now()->startOfMinute());

        $this->gate = Gate::factory()->create(['correlation_window_seconds' => 10]);
        $this->faceCamera = $this->linkCamera(GateCameraRole::FACE);
        $this->plateCamera = $this->linkCamera(GateCameraRole::PLATE);
        $this->travel(1)->seconds();

        $this->correlation = app(GateCorrelationService::class);
    }

    private function linkCamera(GateCameraRole $role): Camera
    {
        $camera = Camera::factory()->create(['site_id' => $this->gate->site_id, 'zone_id' => $this->gate->zone_id]);
        GateCamera::create(['gate_id' => $this->gate->id, 'camera_id' => $camera->id, 'role' => $role]);

        return $camera;
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'GATE_'.uniqid(), 'name' => 'Officer', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function student(): Person
    {
        $person = Person::factory()->create();
        StudentProfile::factory()->for($person)->create(['academic_status' => 'ACTIVE']);

        return $person;
    }

    private function face(?Person $person, int $offsetSeconds = 0): AccessEvent
    {
        return AccessEvent::factory()->create([
            'site_id' => $this->faceCamera->site_id,
            'zone_id' => $this->faceCamera->zone_id,
            'camera_id' => $this->faceCamera->id,
            'person_id' => $person?->id,
            'event_type' => $person !== null ? 'FACE_RECOGNIZED' : 'UNKNOWN_PERSON',
            'occurred_at' => now()->addSeconds($offsetSeconds),
        ]);
    }

    private function plate(?Vehicle $vehicle, int $offsetSeconds = 0): AccessEvent
    {
        return AccessEvent::factory()->create([
            'site_id' => $this->plateCamera->site_id,
            'zone_id' => $this->plateCamera->zone_id,
            'camera_id' => $this->plateCamera->id,
            'event_type' => $vehicle !== null ? 'PLATE_RECOGNIZED' : 'PLATE_UNKNOWN',
            'occurred_at' => now()->addSeconds($offsetSeconds),
            'face_score' => null,
            'metadata' => ['plate' => [
                'plate_number' => $vehicle?->plate_number ?? '9ฮฮ9999',
                'plate_display' => '1กข 1234',
                'plate_province' => 'TH-10',
                'vehicle_uuid' => $vehicle?->uuid,
            ]],
        ]);
    }

    private function ownedVehicle(Person $owner): Vehicle
    {
        $vehicle = Vehicle::factory()->create();
        VehicleOwnershipHistory::factory()->create([
            'person_id' => $owner->id,
            'vehicle_id' => $vehicle->id,
            'effective_from' => now()->subYear(),
        ]);

        return $vehicle;
    }

    public function test_paired_pass_is_decided_on_the_face_event_and_pushed_with_the_gate_block(): void
    {
        Event::fake([AccessEventBroadcast::class]);
        $person = $this->student();
        $face = $this->face($person);
        $plate = $this->plate($this->ownedVehicle($person), 2);

        $pass = $this->correlation->onEventIngested($plate);

        $decision = AccessDecision::where('gate_pass_id', $pass->id)->sole();
        $this->assertSame($face->id, $decision->event_id, 'The face event is the primary event');
        $this->assertSame($person->id, $decision->person_id);
        $this->assertSame(AccessDecisionOutcome::ALLOW, $decision->decision);
        $this->assertSame('STUDENT_ACTIVE', $decision->reason_code);
        $this->assertSame(DecisionSource::POLICY, $decision->decision_source);
        $this->assertTrue($face->latestDecision()->first()->is($decision));
        $this->assertSame(0, $plate->decisions()->count(), 'The plate read of a paired pass stays a record');

        Event::assertDispatched(AccessEventBroadcast::class, function (AccessEventBroadcast $b) use ($face, $pass) {
            $payload = $b->broadcastWith();

            return $payload['event']['uuid'] === $face->uuid
                && $payload['gate_pass']['uuid'] === $pass->uuid
                && $payload['gate_pass']['match_result'] === 'MATCHED'
                && $payload['gate_pass']['gate']['name'] === $this->gate->name
                && $payload['gate_pass']['plate']['plate_display'] === '1กข 1234';
        });
    }

    public function test_plate_only_pass_is_decided_on_the_plate_event_and_alerts_once(): void
    {
        $officer = $this->userWith(['monitor.view']);
        $plate = $this->plate($this->ownedVehicle($this->student()));
        $this->travel(30)->seconds();

        $this->correlation->closeExpired();

        $pass = GatePass::sole();
        $this->assertSame(GateMatchResult::PLATE_ONLY, $pass->match_result);
        $decision = AccessDecision::where('gate_pass_id', $pass->id)->sole();
        $this->assertSame($plate->id, $decision->event_id);
        $this->assertSame([AccessDecisionOutcome::REVIEW, 'GATE_PLATE_ONLY'], [$decision->decision, $decision->reason_code]);

        $notification = Notification::where('user_id', $officer->id)->sole();
        $this->assertSame('EVENT_REVIEW_REQUIRED', $notification->type);
        $this->assertSame($pass->uuid, $notification->data['gate_pass_uuid']);
        $this->assertSame($this->gate->name, $notification->data['gate_name']);
        $this->assertSame('PLATE_ONLY', $notification->data['match_result']);
        $this->assertSame('1กข 1234', $notification->data['plate_display']);

        // The sweep running again changes nothing.
        $this->travel(10)->seconds();
        $this->correlation->closeExpired();
        $this->assertSame(1, AccessDecision::count());
        $this->assertSame(1, Notification::count());
    }

    public function test_a_pass_is_decided_once_and_a_missed_decision_is_retried_by_the_sweep(): void
    {
        $face = $this->face($this->student());
        // A pass whose decision never ran (for example the process died right after creating it).
        $pass = GatePass::create([
            'gate_id' => $this->gate->id,
            'face_event_id' => $face->id,
            'person_id' => $face->person_id,
            'match_result' => GateMatchResult::FACE_ONLY,
            'occurred_at' => $face->occurred_at,
        ]);

        $this->correlation->closeExpired();

        $this->assertSame(1, AccessDecision::where('gate_pass_id', $pass->id)->count());
        $this->assertNull(app(GateDecisionService::class)->decide($pass), 'A decided pass is never decided again');
        $this->assertSame(1, AccessDecision::where('gate_pass_id', $pass->id)->count());
    }

    public function test_an_earlier_officer_ruling_is_never_overridden_by_the_gate(): void
    {
        $face = $this->face(null);
        AccessDecision::factory()->for($face, 'event')->create([
            'decision' => 'ALLOW',
            'decision_source' => 'OFFICER',
            'reason_code' => 'OFFICER_OVERRIDE',
        ]);
        $this->travel(30)->seconds();

        $this->correlation->closeExpired();

        $this->assertSame(1, GatePass::count());
        $this->assertSame(0, AccessDecision::whereNotNull('gate_pass_id')->count());
        $this->assertSame('OFFICER_OVERRIDE', $face->latestDecision()->first()->reason_code);
    }

    public function test_unknown_face_at_a_gate_alerts_once_with_the_gate_decision(): void
    {
        Event::fake([AccessEventBroadcast::class]);
        $officer = $this->userWith(['monitor.view']);
        $node = AiNode::factory()->create();
        $this->faceCamera->update(['ai_node_id' => $node->id]);
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])->postJson('/internal/v1/events/recognition', [
            'event_uuid' => (string) Str::uuid(),
            'camera_uuid' => $this->faceCamera->uuid,
            'ai_node_uuid' => $node->uuid,
            'track_id' => 'face-unknown',
            'occurred_at' => now()->toIso8601String(),
            'classification' => 'UNKNOWN',
            'similarity' => 0.2,
            'distance' => 0.8,
            'model_code' => 'arcface-r50-v1',
            'model_version' => '1.0.0',
            'quality_score' => 0.9,
            'liveness_status' => 'REAL',
            'rejected_locally' => false,
            'reasons' => [],
        ])->assertSuccessful();

        $face = AccessEvent::sole();
        $this->assertSame('UNKNOWN', $face->latestDecision()->first()->decision->value, 'The face decision is still stored');
        $this->assertSame(0, Notification::count(), 'No alert from the face event itself at a gate camera');

        $this->travel(30)->seconds();
        $this->correlation->closeExpired();

        $notification = Notification::where('user_id', $officer->id)->sole();
        $this->assertSame('FACE_ONLY', $notification->data['match_result']);
        $this->assertSame('GATE_FACE_UNKNOWN', $notification->data['reason_code']);
        $this->assertSame('GATE_FACE_UNKNOWN', $face->latestDecision()->first()->reason_code);
    }

    public function test_cameras_outside_a_gate_and_gates_with_the_flag_off_still_alert_immediately(): void
    {
        Event::fake([AccessEventBroadcast::class]);
        $this->userWith(['monitor.view']);
        $node = AiNode::factory()->create();
        $plain = Camera::factory()->create(['site_id' => $this->gate->site_id, 'ai_node_id' => $node->id]);
        $this->faceCamera->update(['ai_node_id' => $node->id]);
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);
        $send = fn (Camera $camera, string $track) => $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/internal/v1/events/recognition', [
                'event_uuid' => (string) Str::uuid(),
                'camera_uuid' => $camera->uuid,
                'ai_node_uuid' => $node->uuid,
                'track_id' => $track,
                'occurred_at' => now()->toIso8601String(),
                'classification' => 'UNKNOWN',
                'similarity' => 0.2,
                'distance' => 0.8,
                'model_code' => 'arcface-r50-v1',
                'model_version' => '1.0.0',
                'quality_score' => 0.9,
                'liveness_status' => 'REAL',
                'rejected_locally' => false,
                'reasons' => [],
            ])->assertSuccessful();

        $send($plain, 'plain-1');
        $this->assertSame(1, Notification::count());

        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.vehicle' => false]);
        $send($this->faceCamera, 'gate-1');
        $this->assertSame(2, Notification::count());
        $this->assertSame(0, GatePass::count());
    }

    public function test_events_api_shows_the_gate_decision_and_the_review_queue_lists_plate_only_passes(): void
    {
        $viewer = $this->userWith(['event.view']);
        $plate = $this->plate(null);
        $this->travel(30)->seconds();
        $this->correlation->closeExpired();
        $pass = GatePass::sole();

        $this->actingAs($viewer, 'web')->getJson("/api/v1/events/{$plate->uuid}")
            ->assertOk()
            ->assertJsonPath('data.current_decision.decision', 'REVIEW')
            ->assertJsonPath('data.current_decision.gate_pass.uuid', $pass->uuid)
            ->assertJsonPath('data.current_decision.gate_pass.match_result', 'PLATE_ONLY')
            ->assertJsonPath('data.current_decision.gate_pass.gate.uuid', $this->gate->uuid)
            ->assertJsonPath('data.decisions.0.gate_pass.uuid', $pass->uuid);

        $queue = $this->actingAs($viewer, 'web')->getJson('/api/v1/events?decision=REVIEW')->assertOk();
        $this->assertSame([$plate->uuid], collect($queue->json('data'))->pluck('uuid')->all());
        $this->assertSame($pass->uuid, $queue->json('data.0.current_decision.gate_pass.uuid'));
    }

    public function test_officer_review_of_a_gate_decision_appends_and_keeps_the_pass_link(): void
    {
        $reviewer = $this->userWith(['event.view', 'event.review']);
        $plate = $this->plate(null);
        $this->travel(30)->seconds();
        $this->correlation->closeExpired();
        $pass = GatePass::sole();
        $gateDecision = AccessDecision::where('gate_pass_id', $pass->id)->sole();

        $this->actingAs($reviewer, 'web')->postJson("/api/v1/events/{$plate->uuid}/review", [
            'decision' => 'DENY',
            'reason_code' => 'OFFICER_OVERRIDE',
            'reason_text' => 'Unknown vehicle, driver turned back.',
        ])->assertStatus(201);

        $latest = $plate->latestDecision()->first();
        $this->assertSame(DecisionSource::OFFICER, $latest->decision_source);
        $this->assertSame($pass->id, $latest->gate_pass_id);
        $this->assertSame('REVIEW', $gateDecision->fresh()->decision->value, 'The gate decision is never edited');
    }

    public function test_a_plain_plate_read_is_still_not_reviewable(): void
    {
        $reviewer = $this->userWith(['event.view', 'event.review']);
        $plate = AccessEvent::factory()->create(['event_type' => 'PLATE_RECOGNIZED', 'face_score' => null]);

        $this->actingAs($reviewer, 'web')->postJson("/api/v1/events/{$plate->uuid}/review", [
            'decision' => 'ALLOW',
            'reason_code' => 'OFFICER_OVERRIDE',
        ])->assertStatus(422)->assertJsonPath('error.code', 'PLATE_EVENT_NOT_REVIEWABLE');
    }
}
