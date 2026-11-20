<?php

namespace Tests\Feature;

use App\Enums\AccessDecisionOutcome;
use App\Enums\GateCameraRole;
use App\Enums\GateMatchResult;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\Camera;
use App\Models\Gate;
use App\Models\GateCamera;
use App\Models\GatePass;
use App\Models\Person;
use App\Models\StudentProfile;
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
 * GATE-009 (ACR-VEH-01 Phase 5): two cars, tailgating, passengers, out-of-order and late events.
 * Owner choices 2026-11-20: late events skip the gate; tailgating stays a PLATE_ONLY review.
 */
class GateCorrelationEdgeCasesTest extends TestCase
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
        $this->seed(PermissionSeeder::class);
        $this->seed(AccessPolicySeeder::class);
        config(['features.locked_kill_switches.vehicle' => null]);
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.vehicle' => true]);
        $this->travelTo(now()->startOfMinute());

        $this->gate = Gate::factory()->create(['correlation_window_seconds' => 10]);
        foreach ([GateCameraRole::FACE, GateCameraRole::PLATE] as $role) {
            $camera = Camera::factory()->create(['site_id' => $this->gate->site_id, 'zone_id' => $this->gate->zone_id]);
            GateCamera::create(['gate_id' => $this->gate->id, 'camera_id' => $camera->id, 'role' => $role]);
            $role === GateCameraRole::FACE ? $this->faceCamera = $camera : $this->plateCamera = $camera;
        }
        $this->travel(1)->seconds();

        $this->service = app(GateCorrelationService::class);
    }

    private function student(): Person
    {
        $person = Person::factory()->create();
        StudentProfile::factory()->for($person)->create(['academic_status' => 'ACTIVE']);

        return $person;
    }

    /** A student and the car they own. */
    private function driver(): array
    {
        $person = $this->student();
        $vehicle = Vehicle::factory()->create();
        VehicleOwnershipHistory::factory()->create([
            'person_id' => $person->id,
            'vehicle_id' => $vehicle->id,
            'effective_from' => now()->subYear(),
        ]);

        return [$person, $vehicle];
    }

    private function face(?Person $person, int $offset = 0, ?int $receivedOffset = null): AccessEvent
    {
        return AccessEvent::factory()->create([
            'site_id' => $this->faceCamera->site_id,
            'zone_id' => $this->faceCamera->zone_id,
            'camera_id' => $this->faceCamera->id,
            'person_id' => $person?->id,
            'event_type' => $person !== null ? 'FACE_RECOGNIZED' : 'UNKNOWN_PERSON',
            'occurred_at' => now()->addSeconds($offset),
            'received_at' => now()->addSeconds($receivedOffset ?? 0),
        ]);
    }

    private function plate(?Vehicle $vehicle, int $offset = 0, ?int $receivedOffset = null): AccessEvent
    {
        return AccessEvent::factory()->create([
            'site_id' => $this->plateCamera->site_id,
            'zone_id' => $this->plateCamera->zone_id,
            'camera_id' => $this->plateCamera->id,
            'event_type' => $vehicle !== null ? 'PLATE_RECOGNIZED' : 'PLATE_UNKNOWN',
            'occurred_at' => now()->addSeconds($offset),
            'received_at' => now()->addSeconds($receivedOffset ?? 0),
            'face_score' => null,
            'metadata' => ['plate' => ['plate_number' => $vehicle?->plate_number ?? '9ฮฮ9999', 'vehicle_uuid' => $vehicle?->uuid]],
        ]);
    }

    private function sweepLater(): void
    {
        $this->travel(10 + GateCorrelationService::CLOSE_GRACE_SECONDS + 1)->seconds();
        $this->service->closeExpired();
    }

    private function passOf(AccessEvent $event): ?GatePass
    {
        return GatePass::where('face_event_id', $event->id)->orWhere('plate_event_id', $event->id)->first();
    }

    public function test_two_cars_close_together_are_matched_in_passing_order_not_by_nearest_time(): void
    {
        [$p1, $v1] = $this->driver();
        [$p2, $v2] = $this->driver();
        // Nearest-in-time would pair plate 1 (t5) with face 2 (t4) and swap the cars.
        $events = [$this->face($p1, 0), $this->face($p2, 4), $this->plate($v1, 5), $this->plate($v2, 9)];

        foreach ($events as $event) {
            $this->assertNull($this->service->onEventIngested($event), 'Ambiguous: left to the sweep');
        }
        $this->sweepLater();

        $this->assertSame(2, GatePass::count());
        $first = $this->passOf($events[0]);
        $this->assertSame([$events[2]->id, GateMatchResult::MATCHED], [$first->plate_event_id, $first->match_result]);
        $second = $this->passOf($events[1]);
        $this->assertSame([$events[3]->id, GateMatchResult::MATCHED], [$second->plate_event_id, $second->match_result]);
        $this->assertSame(2, AccessDecision::whereNotNull('gate_pass_id')->count());
    }

    public function test_unregistered_cars_are_also_matched_in_passing_order(): void
    {
        $face1 = $this->face($this->student(), 0);
        $face2 = $this->face($this->student(), 4);
        $plate1 = $this->plate(null, 5);
        $plate2 = $this->plate(null, 9);
        $this->sweepLater();

        $this->assertSame($plate1->id, $this->passOf($face1)->plate_event_id);
        $this->assertSame($plate2->id, $this->passOf($face2)->plate_event_id);
        $this->assertSame([GateMatchResult::UNKNOWN], GatePass::pluck('match_result')->unique()->values()->all());
    }

    public function test_the_arrival_order_does_not_change_the_result(): void
    {
        [$p1, $v1] = $this->driver();
        [$p2, $v2] = $this->driver();
        // Delivered out of order: plates first, the later car before the earlier one.
        $plate2 = $this->plate($v2, 9);
        $plate1 = $this->plate($v1, 5);
        $face2 = $this->face($p2, 4);
        $face1 = $this->face($p1, 0);
        foreach ([$plate2, $plate1, $face2, $face1] as $event) {
            $this->service->onEventIngested($event);
        }
        $this->sweepLater();

        $this->assertSame($plate1->id, $this->passOf($face1)->plate_event_id);
        $this->assertSame($plate2->id, $this->passOf($face2)->plate_event_id);
        $this->assertSame([GateMatchResult::MATCHED], GatePass::pluck('match_result')->unique()->values()->all());
    }

    public function test_an_unambiguous_pair_is_still_made_at_once(): void
    {
        [$person, $vehicle] = $this->driver();
        $this->face($person);

        $pass = $this->service->onEventIngested($this->plate($vehicle, 2));

        $this->assertSame(GateMatchResult::MATCHED, $pass->match_result);
    }

    public function test_a_tailgating_car_becomes_a_plate_only_pass_set_to_review(): void
    {
        [$person, $vehicle] = $this->driver();
        $face = $this->face($person);
        $pass = $this->service->onEventIngested($this->plate($vehicle, 1));
        $this->assertSame(GateMatchResult::MATCHED, $pass->match_result);

        // A second car right behind, nobody's face seen.
        $tailgater = $this->plate(null, 4);
        $this->assertNull($this->service->onEventIngested($tailgater));
        $this->sweepLater();

        $tail = $this->passOf($tailgater);
        $this->assertSame(GateMatchResult::PLATE_ONLY, $tail->match_result);
        $decision = AccessDecision::where('gate_pass_id', $tail->id)->sole();
        $this->assertSame([AccessDecisionOutcome::REVIEW, 'GATE_PLATE_ONLY'], [$decision->decision, $decision->reason_code]);
        $this->assertSame($pass->id, $this->passOf($face)->id, 'The first pass is unchanged');
    }

    public function test_a_passenger_never_takes_the_drivers_car(): void
    {
        [$driver, $vehicle] = $this->driver();
        $driverFace = $this->face($driver, 0);
        $passengerFace = $this->face($this->student(), 1); // nearer in time to the plate
        $plate = $this->plate($vehicle, 2);

        $this->assertNull($this->service->onEventIngested($plate), 'Two faces: left to the sweep');
        $this->sweepLater();

        $this->assertSame([$plate->id, GateMatchResult::MATCHED], [$this->passOf($driverFace)->plate_event_id, $this->passOf($driverFace)->match_result]);
        $this->assertSame(GateMatchResult::FACE_ONLY, $this->passOf($passengerFace)->match_result);
    }

    public function test_events_delivered_late_skip_the_gate(): void
    {
        [$person, $vehicle] = $this->driver();
        // A node was offline: both reads arrive three minutes after they happened.
        $face = $this->face($person, -180);
        $plate = $this->plate($vehicle, -178);

        $this->assertFalse($this->service->covers($face), 'Handled like an ordinary camera');
        $this->assertNull($this->service->onEventIngested($face));
        $this->assertNull($this->service->onEventIngested($plate));
        $this->sweepLater();

        $this->assertSame(0, GatePass::count());
    }

    public function test_a_little_delivery_delay_is_still_a_gate_event(): void
    {
        [$person, $vehicle] = $this->driver();
        $face = $this->face($person, -GateCorrelationService::STALE_SECONDS);

        $this->assertTrue($this->service->covers($face));
        $pass = $this->service->onEventIngested($this->plate($vehicle, -GateCorrelationService::STALE_SECONDS + 2));

        $this->assertSame(GateMatchResult::MATCHED, $pass->match_result);
    }

    public function test_a_partner_arriving_after_its_pass_closed_gets_its_own_pass(): void
    {
        [$person, $vehicle] = $this->driver();
        $face = $this->face($person);
        $this->sweepLater();
        $this->assertSame(GateMatchResult::FACE_ONLY, $this->passOf($face)->match_result);

        // The plate read happened 2 s after the face but arrives now (still within STALE_SECONDS).
        $plate = $this->plate($vehicle, -14);
        $this->assertNull($this->service->onEventIngested($plate));
        $this->sweepLater();

        $this->assertSame(GateMatchResult::FACE_ONLY, $this->passOf($face)->fresh()->match_result, 'Passes are immutable');
        $this->assertSame(GateMatchResult::PLATE_ONLY, $this->passOf($plate)->match_result);
    }

    public function test_a_busy_gate_keeps_deciding_while_cars_keep_coming(): void
    {
        $events = [];
        // A car every 6 s for a minute; ingest-time pairing never ran, so only the sweep pairs.
        for ($car = 0; $car < 10; $car++) {
            [$person, $vehicle] = $this->driver();
            $events[] = [$this->face($person), $this->plate($vehicle, 1)];
            $this->travel(6)->seconds();
            $this->service->closeExpired();
        }

        // The earlier cars were written while traffic continued; only the last few still wait.
        $this->assertGreaterThanOrEqual(7, GatePass::count());
        $this->sweepLater();
        $this->assertSame(10, GatePass::count());
        foreach ($events as [$face, $plate]) {
            $this->assertSame($plate->id, $this->passOf($face)->plate_event_id);
        }
    }
}
