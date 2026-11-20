<?php

namespace Tests\Feature;

use App\Enums\AccessDecisionOutcome;
use App\Enums\DecisionSource;
use App\Enums\GateCameraRole;
use App\Enums\GateMatchResult;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Enums\VehicleStatus;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Gate;
use App\Models\GateCamera;
use App\Models\GatePass;
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
use App\Services\System\SystemSettingService;
use Database\Seeders\AccessPolicySeeder;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Phase 5 Smart Gate consolidated acceptance suite (GATE-010, ACR-VEH-01 items 6-9, D2, D5).
 *
 * Each guarantee below is proven in isolation by GATE-001..GATE-009's own tests; this suite asserts
 * them together, like the Module01..05 acceptance suites: architecture invariants, one end-to-end
 * pass through the real ingestion endpoints, the D2 decision table, and the security boundaries.
 * Decision and notification only: nothing here, or anywhere, opens a barrier (D5).
 */
class SmartGateAcceptanceSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Gate $gate;

    private Camera $faceCamera;

    private Camera $plateCamera;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(EventTypeSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(AccessPolicySeeder::class);
        config(['features.locked_kill_switches.vehicle' => null]);
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.vehicle' => true]);
        $this->travelTo(now()->setTime(12, 0));

        $this->gate = Gate::factory()->create(['name' => 'ประตูหน้า', 'correlation_window_seconds' => 10]);
        $this->faceCamera = $this->linkCamera(GateCameraRole::FACE);
        $this->plateCamera = $this->linkCamera(GateCameraRole::PLATE);
        $this->travel(1)->seconds();
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
        $role = Role::create(['code' => 'SG_'.uniqid(), 'name' => 'Smart Gate Test', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
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

    private function vehicleOf(?Person $owner, VehicleStatus $status = VehicleStatus::ACTIVE): Vehicle
    {
        $vehicle = Vehicle::factory()->create(['status' => $status]);
        if ($owner !== null) {
            VehicleOwnershipHistory::factory()->create([
                'person_id' => $owner->id,
                'vehicle_id' => $vehicle->id,
                'effective_from' => now()->subYear(),
            ]);
        }

        return $vehicle;
    }

    private function face(?Person $person, int $offset = 0, string $type = 'FACE_RECOGNIZED'): AccessEvent
    {
        return AccessEvent::factory()->create([
            'site_id' => $this->gate->site_id,
            'zone_id' => $this->gate->zone_id,
            'camera_id' => $this->faceCamera->id,
            'person_id' => $person?->id,
            'event_type' => $person !== null ? $type : ($type === 'FACE_RECOGNIZED' ? 'UNKNOWN_PERSON' : $type),
            'occurred_at' => now()->addSeconds($offset),
        ]);
    }

    private function plate(?Vehicle $vehicle, int $offset = 0): AccessEvent
    {
        return AccessEvent::factory()->create([
            'site_id' => $this->gate->site_id,
            'zone_id' => $this->gate->zone_id,
            'camera_id' => $this->plateCamera->id,
            'event_type' => $vehicle !== null ? 'PLATE_RECOGNIZED' : 'PLATE_UNKNOWN',
            'occurred_at' => now()->addSeconds($offset),
            'face_score' => null,
            'metadata' => ['plate' => [
                'plate_number' => $vehicle?->plate_number ?? '9ฮฮ9999',
                'vehicle_uuid' => $vehicle?->uuid,
                'vehicle_status' => $vehicle?->status?->value,
            ]],
        ]);
    }

    /** Ingest-time pairing, then the sweep, as in production. */
    private function correlate(AccessEvent ...$events): void
    {
        $service = app(GateCorrelationService::class);
        foreach ($events as $event) {
            $service->onEventIngested($event);
        }
        $this->travel(30)->seconds();
        $service->closeExpired();
    }

    private function gateDecisionFor(AccessEvent $event): AccessDecision
    {
        $pass = GatePass::where('face_event_id', $event->id)->orWhere('plate_event_id', $event->id)->sole();

        return AccessDecision::where('gate_pass_id', $pass->id)->orderBy('id')->firstOrFail();
    }

    public function test_architecture_invariants(): void
    {
        foreach (['gates', 'gate_cameras', 'gate_passes'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        // A pass links events; it holds no decision and no device command.
        foreach (['decision', 'command', 'relay', 'barrier', 'open'] as $column) {
            $this->assertFalse(Schema::hasColumn('gate_passes', $column), "gate_passes.{$column}");
        }
        $this->assertTrue(Schema::hasColumn('access_decisions', 'gate_pass_id'));

        // D5 / golden assertion 1: no route drives a barrier, and AI nodes cannot write passes.
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $this->assertDoesNotMatchRegularExpression('/barrier|relay|actuat|open-gate|gate.*\/open/i', $uri);
            if (str_starts_with($uri, 'internal/')) {
                $this->assertStringNotContainsString('gate', $uri, 'AI nodes never create gate passes');
            }
        }

        // Passes are immutable.
        $pass = GatePass::factory()->create();
        $this->expectException(LogicException::class);
        $pass->update(['match_result' => GateMatchResult::UNKNOWN]);
    }

    public function test_end_to_end_through_the_ingestion_endpoints(): void
    {
        config(['features.locked_kill_switches.alpr' => null, 'alpr.ocr_min_confidence' => 0.9]);
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.alpr' => true]);
        $node = AiNode::factory()->create();
        $this->faceCamera->update(['ai_node_id' => $node->id]);
        $this->plateCamera->update(['ai_node_id' => $node->id, 'plate_recognition_enabled' => true]);
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);
        $auth = ['Authorization' => "Bearer {$token}"];

        $person = $this->student();
        $vehicle = Vehicle::factory()->create(['plate_number' => '1กข1234', 'plate_province' => 'TH-10']);
        VehicleOwnershipHistory::factory()->create(['person_id' => $person->id, 'vehicle_id' => $vehicle->id, 'effective_from' => now()->subYear()]);

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
        // The stored face event as ingested, before correlation, the gate decision and review.
        $before = AccessEvent::where('camera_id', $this->faceCamera->id)->sole()->getRawOriginal();
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
        $faceEvent = $pass->faceEvent;

        // One decision for the pass, on the face event, ALLOW by the person's own policy.
        $decision = AccessDecision::where('gate_pass_id', $pass->id)->sole();
        $this->assertSame($faceEvent->id, $decision->event_id);
        $this->assertSame(AccessDecisionOutcome::ALLOW, $decision->decision);

        // Visible in events, history and reports.
        $user = $this->userWith(['event.view', 'event.review', 'report.view']);
        $this->actingAs($user, 'web')->getJson("/api/v1/events/{$faceEvent->uuid}")->assertOk()
            ->assertJsonPath('data.current_decision.gate_pass.uuid', $pass->uuid);
        $this->actingAs($user, 'web')->getJson('/api/v1/history/gate-passes')->assertOk()
            ->assertJsonPath('data.0.uuid', $pass->uuid)
            ->assertJsonPath('data.0.decision.decision', 'ALLOW');
        $this->actingAs($user, 'web')->getJson('/api/v1/reports/daily')->assertOk()
            ->assertJsonPath('data.gates.0.by_match_result.MATCHED', 1);

        // An officer's review appends and keeps the pass link; nothing is rewritten.
        $this->actingAs($user, 'web')->postJson("/api/v1/events/{$faceEvent->uuid}/review", [
            'decision' => 'DENY',
            'reason_code' => 'OFFICER_OVERRIDE',
            'reason_text' => 'Pilot acceptance check.',
        ])->assertStatus(201);
        $this->assertSame(AccessDecisionOutcome::ALLOW, $decision->fresh()->decision);
        $this->assertSame(DecisionSource::OFFICER, $faceEvent->latestDecision()->first()->decision_source);
        $this->assertSame($pass->id, $faceEvent->latestDecision()->first()->gate_pass_id);
        $this->assertSame($before, $faceEvent->fresh()->getRawOriginal(), 'Events are never modified');
    }

    public function test_d2_decision_table(): void
    {
        // Face MATCH + own car -> ALLOW (MATCHED).
        $owner = $this->student();
        $matched = $this->face($owner);
        $this->correlate($matched, $this->plate($this->vehicleOf($owner), 1));
        $this->assertSame(AccessDecisionOutcome::ALLOW, $this->gateDecisionFor($matched)->decision);

        // Face MATCH + someone else's car -> ALLOW with the note (MISMATCH).
        $driver = $this->student();
        $mismatch = $this->face($driver);
        $this->correlate($mismatch, $this->plate($this->vehicleOf($this->student()), 1));
        $decision = $this->gateDecisionFor($mismatch);
        $this->assertSame(AccessDecisionOutcome::ALLOW, $decision->decision);
        $this->assertSame(GateMatchResult::MISMATCH, $decision->gatePass->match_result);

        // Face UNKNOWN + known plate -> REVIEW.
        $unknownFace = $this->face(null);
        $this->correlate($unknownFace, $this->plate($this->vehicleOf($this->student()), 1));
        $this->assertSame([AccessDecisionOutcome::REVIEW, 'GATE_FACE_UNKNOWN'], [$this->gateDecisionFor($unknownFace)->decision, $this->gateDecisionFor($unknownFace)->reason_code]);

        // Failed liveness at the gate -> REVIEW.
        $spoof = $this->face($this->student(), 0, 'LIVENESS_FAILED');
        $this->correlate($spoof);
        $this->assertSame(AccessDecisionOutcome::REVIEW, $this->gateDecisionFor($spoof)->decision);

        // BLOCKED vehicle -> DENY, even with its owner's face.
        $blockedOwner = $this->student();
        $blocked = $this->face($blockedOwner);
        $this->correlate($blocked, $this->plate($this->vehicleOf($blockedOwner, VehicleStatus::BLOCKED), 1));
        $this->assertSame([AccessDecisionOutcome::DENY, 'GATE_VEHICLE_BLOCKED'], [$this->gateDecisionFor($blocked)->decision, $this->gateDecisionFor($blocked)->reason_code]);
    }

    public function test_a_plate_alone_never_allows(): void
    {
        $cases = [
            'registered, owned' => $this->vehicleOf($this->student()),
            'registered, no owner' => $this->vehicleOf(null),
            'inactive' => $this->vehicleOf($this->student(), VehicleStatus::INACTIVE),
            'blocked' => $this->vehicleOf($this->student(), VehicleStatus::BLOCKED),
            'unregistered' => null,
        ];

        foreach ($cases as $label => $vehicle) {
            $plate = $this->plate($vehicle);
            $this->correlate($plate);
            $decision = $this->gateDecisionFor($plate);
            $this->assertSame(GateMatchResult::PLATE_ONLY, $decision->gatePass->match_result, $label);
            $this->assertNotSame(AccessDecisionOutcome::ALLOW, $decision->decision, $label);
            $this->assertNull($decision->person_id, "{$label}: a plate never identifies a person");
        }
    }

    public function test_fails_closed_without_the_vehicle_module_or_permissions(): void
    {
        $viewer = $this->userWith(['gate.view', 'report.view']);
        $this->actingAs($viewer, 'web')->postJson('/api/v1/gates', [])->assertForbidden();
        $this->actingAs($this->userWith(['event.view']), 'web')->getJson('/api/v1/history/gate-passes')->assertForbidden();

        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.vehicle' => false]);
        foreach (['/api/v1/gates', '/api/v1/history/gate-passes'] as $uri) {
            $this->actingAs($viewer, 'web')->getJson($uri)
                ->assertForbidden()->assertJsonPath('error_code', 'FEATURE_DISABLED');
        }

        // No passes and no gate decisions while the module is off.
        $face = $this->face($this->student());
        $this->correlate($face, $this->plate($this->vehicleOf(null), 1));
        $this->assertSame(0, GatePass::count());
        $this->assertSame(0, AccessDecision::whereNotNull('gate_pass_id')->count());
        $this->assertFalse(app(GateCorrelationService::class)->covers($face), 'Alerts fall back to the ordinary path');
    }
}
