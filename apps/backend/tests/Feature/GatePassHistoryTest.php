<?php

namespace Tests\Feature;

use App\Enums\GateCameraRole;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AccessEvent;
use App\Models\AuditLog;
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
use App\Services\Gate\GateCorrelationService;
use App\Services\System\SystemSettingService;
use Database\Seeders\AccessPolicySeeder;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GATE-008 (ACR-VEH-01 Phase 5): gate pass history search and the per-gate report section.
 * Read only, on the reporting boundary (report.view + feature.vehicle), scoped by the pass's gate.
 */
class GatePassHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Gate $gate;

    private Gate $otherGate;

    /** @var array<int, array{face: Camera, plate: Camera}> */
    private array $cameras = [];

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
        // Midday, so every pass in a test falls on the same report day.
        $this->travelTo(now()->setTime(12, 0));

        $this->gate = Gate::factory()->create(['name' => 'ประตูหน้า', 'correlation_window_seconds' => 10]);
        $this->otherGate = Gate::factory()->create(['name' => 'ประตูหลัง', 'correlation_window_seconds' => 10]);
        $this->assertNotSame($this->gate->site_id, $this->otherGate->site_id);
        foreach ([$this->gate, $this->otherGate] as $gate) {
            $this->cameras[$gate->id] = [
                'face' => $this->linkCamera($gate, GateCameraRole::FACE),
                'plate' => $this->linkCamera($gate, GateCameraRole::PLATE),
            ];
        }
        $this->travel(1)->seconds();

        $this->correlation = app(GateCorrelationService::class);
    }

    private function linkCamera(Gate $gate, GateCameraRole $role): Camera
    {
        $camera = Camera::factory()->create(['site_id' => $gate->site_id, 'zone_id' => $gate->zone_id]);
        GateCamera::create(['gate_id' => $gate->id, 'camera_id' => $camera->id, 'role' => $role]);

        return $camera;
    }

    private function userWith(array $codes, ?int $siteId = null): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'GH_'.uniqid(), 'name' => 'Officer', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'site_id' => $siteId]);

        return $user;
    }

    private function student(): Person
    {
        $person = Person::factory()->create();
        StudentProfile::factory()->for($person)->create(['academic_status' => 'ACTIVE']);

        return $person;
    }

    private function ownedVehicle(Person $owner, string $plate): Vehicle
    {
        $vehicle = Vehicle::factory()->create(['plate_number' => $plate]);
        VehicleOwnershipHistory::factory()->create([
            'person_id' => $owner->id,
            'vehicle_id' => $vehicle->id,
            'effective_from' => now()->subYear(),
        ]);

        return $vehicle;
    }

    private function face(Gate $gate, ?Person $person): AccessEvent
    {
        $camera = $this->cameras[$gate->id]['face'];

        return AccessEvent::factory()->create([
            'site_id' => $camera->site_id,
            'zone_id' => $camera->zone_id,
            'camera_id' => $camera->id,
            'person_id' => $person?->id,
            'event_type' => $person !== null ? 'FACE_RECOGNIZED' : 'UNKNOWN_PERSON',
            'occurred_at' => now(),
        ]);
    }

    private function plate(Gate $gate, ?Vehicle $vehicle, string $plateNumber = '9ฮฮ9999'): AccessEvent
    {
        $camera = $this->cameras[$gate->id]['plate'];

        return AccessEvent::factory()->create([
            'site_id' => $camera->site_id,
            'zone_id' => $camera->zone_id,
            'camera_id' => $camera->id,
            'event_type' => $vehicle !== null ? 'PLATE_RECOGNIZED' : 'PLATE_UNKNOWN',
            'occurred_at' => now(),
            'face_score' => null,
            'metadata' => ['plate' => [
                'plate_number' => $vehicle?->plate_number ?? $plateNumber,
                'plate_display' => $vehicle?->plate_number ?? $plateNumber,
                'plate_province' => 'TH-10',
                'vehicle_uuid' => $vehicle?->uuid,
            ]],
        ]);
    }

    /** A matched pass (face + own car) at the gate. */
    private function matchedPass(Gate $gate, Person $person, Vehicle $vehicle): GatePass
    {
        $this->face($gate, $person);
        $pass = $this->correlation->onEventIngested($this->plate($gate, $vehicle));
        $this->travel(1)->minutes();

        return $pass;
    }

    /** A lone plate read that the sweep turns into a PLATE_ONLY pass. */
    private function plateOnlyPass(Gate $gate, string $plateNumber): GatePass
    {
        $event = $this->plate($gate, null, $plateNumber);
        $this->travel(30)->seconds();
        $this->correlation->closeExpired();
        $this->travel(1)->minutes();

        return GatePass::where('plate_event_id', $event->id)->sole();
    }

    private function search(User $user, array $params = [])
    {
        return $this->actingAs($user, 'web')->getJson('/api/v1/history/gate-passes?'.http_build_query($params));
    }

    public function test_lists_passes_newest_first_with_gate_person_vehicle_and_current_decision(): void
    {
        $person = $this->student();
        $vehicle = $this->ownedVehicle($person, '1กข1234');
        $matched = $this->matchedPass($this->gate, $person, $vehicle);
        $plateOnly = $this->plateOnlyPass($this->gate, '2คง5678');

        $response = $this->search($this->userWith(['report.view']))->assertOk();

        $this->assertSame([$plateOnly->uuid, $matched->uuid], collect($response->json('data'))->pluck('uuid')->all());
        $this->assertSame(2, $response->json('meta.pagination.total'));

        $row = $response->json('data.1');
        $this->assertSame('MATCHED', $row['match_result']);
        $this->assertSame(['uuid' => $this->gate->uuid, 'code' => $this->gate->code, 'name' => 'ประตูหน้า'], $row['gate']);
        $this->assertSame($person->uuid, $row['person']['uuid']);
        $this->assertSame($vehicle->uuid, $row['vehicle']['uuid']);
        $this->assertSame('1กข1234', $row['plate']['plate_number']);
        $this->assertSame(['ALLOW', 'STUDENT_ACTIVE', 'POLICY'], [$row['decision']['decision'], $row['decision']['reason_code'], $row['decision']['decision_source']]);
        $this->assertArrayNotHasKey('id', $row);

        $plateRow = $response->json('data.0');
        $this->assertNull($plateRow['person']);
        $this->assertSame(['REVIEW', 'GATE_PLATE_ONLY'], [$plateRow['decision']['decision'], $plateRow['decision']['reason_code']]);
    }

    public function test_needs_report_view_and_the_vehicle_module(): void
    {
        $this->search($this->userWith(['gate.view', 'event.view']))->assertForbidden();

        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.vehicle' => false]);
        $this->search($this->userWith(['report.view']))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FEATURE_DISABLED');
    }

    public function test_filters_by_gate_match_result_person_vehicle_and_time(): void
    {
        $person = $this->student();
        $vehicle = $this->ownedVehicle($person, '1กข1234');
        $from = now()->toISOString();
        $matched = $this->matchedPass($this->gate, $person, $vehicle);
        $to = $matched->occurred_at->copy()->addSecond()->toISOString();
        $plateOnly = $this->plateOnlyPass($this->gate, '2คง5678');
        $other = $this->plateOnlyPass($this->otherGate, '3จฉ9012');
        $user = $this->userWith(['report.view']);

        $uuids = fn (array $params) => collect($this->search($user, $params)->assertOk()->json('data'))->pluck('uuid')->all();

        $this->assertSame([$other->uuid], $uuids(['gate_uuid' => $this->otherGate->uuid]));
        $this->assertSame([$other->uuid, $plateOnly->uuid], $uuids(['match_result' => 'PLATE_ONLY']));
        $this->assertSame([$matched->uuid], $uuids(['person_uuid' => $person->uuid]));
        $this->assertSame([$matched->uuid], $uuids(['vehicle_uuid' => $vehicle->uuid]));
        $this->assertSame([$matched->uuid], $uuids(['from' => $from, 'to' => $to]));
        $this->assertSame([$other->uuid], $uuids(['site_uuid' => $this->otherGate->site->uuid]));
        // An unknown uuid is a real filter that matches nothing.
        $this->assertSame([], $uuids(['gate_uuid' => '00000000-0000-4000-8000-000000000000']));
        $this->search($user, ['match_result' => 'OPEN'])->assertStatus(422);
    }

    public function test_plate_search_is_normalised_and_audited(): void
    {
        $plateOnly = $this->plateOnlyPass($this->gate, '2คง5678');
        $this->plateOnlyPass($this->gate, '3จฉ9012');
        $user = $this->userWith(['report.view']);

        $this->assertSame([$plateOnly->uuid], collect($this->search($user, ['plate' => '2คง-5678'])->assertOk()->json('data'))->pluck('uuid')->all());
        $this->assertSame([], $this->search($user, ['plate' => '!!!'])->assertOk()->json('data'));

        $logs = AuditLog::where('action', 'PLATE_SEARCHED')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['source' => 'gate_passes', 'searched' => '2คง-5678', 'plate_number' => '2คง5678'], $logs[0]->new_values);
    }

    public function test_decision_filter_follows_the_current_decision_after_an_officer_review(): void
    {
        $plateOnly = $this->plateOnlyPass($this->gate, '2คง5678');
        $user = $this->userWith(['report.view', 'event.view', 'event.review']);

        $this->assertSame([$plateOnly->uuid], collect($this->search($user, ['decision' => 'REVIEW'])->json('data'))->pluck('uuid')->all());

        $this->actingAs($user, 'web')->postJson("/api/v1/events/{$plateOnly->plateEvent->uuid}/review", [
            'decision' => 'DENY',
            'reason_code' => 'OFFICER_OVERRIDE',
            'reason_text' => 'Unknown vehicle turned away.',
        ])->assertStatus(201);

        $this->assertSame([], $this->search($user, ['decision' => 'REVIEW'])->json('data'));
        $rows = $this->search($user, ['decision' => 'DENY'])->json('data');
        $this->assertSame([$plateOnly->uuid], collect($rows)->pluck('uuid')->all());
        $this->assertSame('OFFICER', $rows[0]['decision']['decision_source']);
    }

    public function test_a_site_scoped_user_sees_only_passes_at_gates_in_their_site(): void
    {
        $mine = $this->plateOnlyPass($this->gate, '2คง5678');
        $this->plateOnlyPass($this->otherGate, '3จฉ9012');
        $user = $this->userWith(['report.view'], $this->gate->site_id);

        $this->assertSame([$mine->uuid], collect($this->search($user)->assertOk()->json('data'))->pluck('uuid')->all());
        $this->assertSame([], $this->search($user, ['gate_uuid' => $this->otherGate->uuid])->json('data'));
    }

    public function test_reports_count_passes_per_gate_by_match_result_and_current_decision(): void
    {
        $person = $this->student();
        $this->matchedPass($this->gate, $person, $this->ownedVehicle($person, '1กข1234'));
        $this->plateOnlyPass($this->gate, '2คง5678');
        $this->plateOnlyPass($this->otherGate, '3จฉ9012');
        // A pass whose decision has not been written yet counts as undecided.
        $face = $this->face($this->gate, null);
        GatePass::create([
            'gate_id' => $this->gate->id,
            'face_event_id' => $face->id,
            'match_result' => 'FACE_ONLY',
            'occurred_at' => now(),
        ]);
        $user = $this->userWith(['report.view']);

        $gates = $this->actingAs($user, 'web')->getJson('/api/v1/reports/daily')->assertOk()->json('data.gates');

        $this->assertSame(['ประตูหน้า', 'ประตูหลัง'], array_column($gates, 'gate_name'));
        $front = $gates[0];
        $this->assertSame($this->gate->uuid, $front['gate_uuid']);
        $this->assertSame(3, $front['total']);
        $this->assertSame(['MATCHED' => 1, 'MISMATCH' => 0, 'FACE_ONLY' => 1, 'PLATE_ONLY' => 1, 'UNKNOWN' => 0], $front['by_match_result']);
        $this->assertSame(['ALLOW' => 1, 'REVIEW' => 1, 'DENY' => 0, 'UNKNOWN' => 0, 'UNDECIDED' => 1], $front['by_decision']);
        $this->assertSame(1, $gates[1]['total']);

        // Site-limited report: only that site's gates.
        $scoped = $this->userWith(['report.view'], $this->otherGate->site_id);
        $this->assertSame(['ประตูหลัง'], array_column(
            $this->actingAs($scoped, 'web')->getJson('/api/v1/reports/daily')->assertOk()->json('data.gates'),
            'gate_name',
        ));

        // Another day: no passes, no rows.
        $this->assertSame([], $this->actingAs($user, 'web')->getJson('/api/v1/reports/daily?date='.now()->subDays(3)->toDateString())->json('data.gates'));
    }
}
