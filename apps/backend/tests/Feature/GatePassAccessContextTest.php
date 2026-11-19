<?php

namespace Tests\Feature;

use App\Enums\AccessDecisionOutcome;
use App\Enums\DecisionSource;
use App\Enums\GateMatchResult;
use App\Enums\GateMode;
use App\Enums\VehicleRelation;
use App\Enums\VehicleStatus;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AccessPolicy;
use App\Models\Gate;
use App\Models\GatePass;
use App\Models\Person;
use App\Models\StudentProfile;
use App\Models\Vehicle;
use App\Models\VehicleOwnershipHistory;
use App\Services\Event\AccessContextResolver;
use App\Services\Event\AccessPolicyMatcher;
use Database\Seeders\AccessPolicySeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GATE-005 (ACR-VEH-01 Phase 5 item 8): AccessContext for a gate pass and the gate dimensions of
 * the policy matcher. Evaluation only: nothing here writes a decision (GATE-007).
 */
class GatePassAccessContextTest extends TestCase
{
    use RefreshDatabase;

    private Gate $gate;

    private AccessContextResolver $resolver;

    private AccessPolicyMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EventTypeSeeder::class);
        $this->gate = Gate::factory()->create();
        $this->resolver = app(AccessContextResolver::class);
        $this->matcher = app(AccessPolicyMatcher::class);
    }

    private function student(string $status = 'ACTIVE'): Person
    {
        $person = Person::factory()->create();
        StudentProfile::factory()->for($person)->create(['academic_status' => $status]);

        return $person;
    }

    private function event(string $type, ?Person $person = null): AccessEvent
    {
        return AccessEvent::factory()->create([
            'site_id' => $this->gate->site_id,
            'zone_id' => null,
            'person_id' => $person?->id,
            'event_type' => $type,
            'occurred_at' => now()->subSeconds(30),
        ]);
    }

    private function pass(GateMatchResult $result, ?AccessEvent $face, ?AccessEvent $plate, ?Vehicle $vehicle = null, ?Gate $gate = null): GatePass
    {
        return GatePass::create([
            'gate_id' => ($gate ?? $this->gate)->id,
            'face_event_id' => $face?->id,
            'plate_event_id' => $plate?->id,
            'person_id' => $face?->person_id,
            'vehicle_id' => $vehicle?->id,
            'match_result' => $result,
            'occurred_at' => ($face ?? $plate)->occurred_at,
        ]);
    }

    private function link(Person $person, Vehicle $vehicle, VehicleRelation $relation = VehicleRelation::OWNER): void
    {
        VehicleOwnershipHistory::factory()->create([
            'person_id' => $person->id,
            'vehicle_id' => $vehicle->id,
            'relation' => $relation,
            'effective_from' => now()->subYear(),
        ]);
    }

    private function gatePolicy(array $attributes): AccessPolicy
    {
        return AccessPolicy::factory()->create(array_merge([
            'subject_type' => null,
            'subject_status' => null,
            'gate_mode' => GateMode::FACE_PRIMARY,
            'priority' => 5,
        ], $attributes));
    }

    public function test_access_policies_have_the_gate_dimensions(): void
    {
        $this->assertTrue(Schema::hasColumns('access_policies', ['gate_id', 'gate_mode', 'match_result', 'vehicle_status']));

        $policy = $this->gatePolicy([
            'gate_id' => $this->gate->id,
            'match_result' => GateMatchResult::MISMATCH,
            'vehicle_status' => VehicleStatus::BLOCKED,
        ])->fresh();

        $this->assertSame(GateMode::FACE_PRIMARY, $policy->gate_mode);
        $this->assertSame(GateMatchResult::MISMATCH, $policy->match_result);
        $this->assertSame(VehicleStatus::BLOCKED, $policy->vehicle_status);
        $this->assertTrue($policy->gate->is($this->gate));
    }

    public function test_matched_pass_context_carries_gate_vehicle_and_ownership_link(): void
    {
        $person = $this->student();
        $vehicle = Vehicle::factory()->create(['status' => VehicleStatus::ACTIVE]);
        $this->link($person, $vehicle, VehicleRelation::DRIVER);
        $pass = $this->pass(GateMatchResult::MATCHED, $this->event('FACE_RECOGNIZED', $person), $this->event('PLATE_RECOGNIZED'), $vehicle);

        $context = $this->resolver->resolvePass($pass);

        $this->assertSame('STUDENT', $context->subjectType);
        $this->assertSame('ACTIVE', $context->subjectStatus);
        $this->assertSame($this->gate->site_id, $context->siteId);
        $this->assertSame($this->gate->zone_id, $context->zoneId);
        $this->assertSame('FACE_RECOGNIZED', $context->eventType);
        $this->assertTrue($context->isGatePass());
        $this->assertSame($this->gate->id, $context->gateId);
        $this->assertSame('FACE_PRIMARY', $context->gateMode);
        $this->assertSame('MATCHED', $context->matchResult);
        $this->assertSame($vehicle->id, $context->vehicleId);
        $this->assertSame('ACTIVE', $context->vehicleStatus);
        $this->assertSame('DRIVER', $context->ownershipRelation);
    }

    public function test_mismatch_pass_has_no_ownership_link_and_reports_a_blocked_vehicle(): void
    {
        $person = $this->student();
        $vehicle = Vehicle::factory()->create(['status' => VehicleStatus::BLOCKED]);
        // Someone else's car: the link is to another person.
        $this->link(Person::factory()->create(), $vehicle);
        $pass = $this->pass(GateMatchResult::MISMATCH, $this->event('FACE_RECOGNIZED', $person), $this->event('PLATE_RECOGNIZED'), $vehicle);

        $context = $this->resolver->resolvePass($pass);

        $this->assertSame('MISMATCH', $context->matchResult);
        $this->assertSame('BLOCKED', $context->vehicleStatus);
        $this->assertNull($context->ownershipRelation);
    }

    public function test_plate_only_pass_never_takes_the_subject_from_the_vehicle_owner(): void
    {
        $owner = $this->student();
        $vehicle = Vehicle::factory()->create();
        $this->link($owner, $vehicle);
        $pass = $this->pass(GateMatchResult::PLATE_ONLY, null, $this->event('PLATE_RECOGNIZED'), $vehicle);

        $context = $this->resolver->resolvePass($pass);

        // Face stays the identity (D2): no face, no subject.
        $this->assertNull($context->subjectType);
        $this->assertNull($context->subjectStatus);
        $this->assertSame('PLATE_RECOGNIZED', $context->eventType);
        $this->assertSame('PLATE_ONLY', $context->matchResult);
        $this->assertSame($vehicle->id, $context->vehicleId);
        $this->assertNull($context->ownershipRelation);
    }

    public function test_face_only_and_redacted_passes_have_no_vehicle(): void
    {
        $faceOnly = $this->resolver->resolvePass(
            $this->pass(GateMatchResult::FACE_ONLY, $this->event('UNKNOWN_PERSON'), null),
        );
        $this->assertSame('UNKNOWN_PERSON', $faceOnly->eventType);
        $this->assertSame('FACE_ONLY', $faceOnly->matchResult);
        $this->assertNull($faceOnly->vehicleId);
        $this->assertNull($faceOnly->vehicleStatus);

        // Plate read redacted by retention: vehicle_id cleared on the pass.
        $redacted = $this->resolver->resolvePass(
            $this->pass(GateMatchResult::UNKNOWN, $this->event('UNKNOWN_PERSON'), $this->event('PLATE_RECOGNIZED')),
        );
        $this->assertSame('UNKNOWN', $redacted->matchResult);
        $this->assertNull($redacted->vehicleId);
        $this->assertNull($redacted->vehicleStatus);
    }

    public function test_ordinary_event_context_has_no_gate_fields(): void
    {
        $context = $this->resolver->resolve($this->event('FACE_RECOGNIZED', $this->student())->fresh());

        $this->assertFalse($context->isGatePass());
        $this->assertNull($context->gateId);
        $this->assertNull($context->gateMode);
        $this->assertNull($context->matchResult);
        $this->assertNull($context->vehicleId);
        $this->assertNull($context->vehicleStatus);
        $this->assertNull($context->ownershipRelation);
    }

    public function test_gate_policy_matches_a_gate_pass_but_never_an_ordinary_event(): void
    {
        $this->seed(AccessPolicySeeder::class);
        $policy = $this->gatePolicy([
            'match_result' => GateMatchResult::MISMATCH,
            'decision' => AccessDecisionOutcome::REVIEW,
            'reason_code' => 'GATE_TEST_MISMATCH',
        ]);
        $person = $this->student();
        $vehicle = Vehicle::factory()->create();
        $face = $this->event('FACE_RECOGNIZED', $person);
        $pass = $this->pass(GateMatchResult::MISMATCH, $face, $this->event('PLATE_RECOGNIZED'), $vehicle);

        $gateResult = $this->matcher->match($this->resolver->resolvePass($pass));
        $this->assertSame($policy->id, $gateResult->matchedPolicyId);
        $this->assertSame(AccessDecisionOutcome::REVIEW, $gateResult->decision);

        // The same face event on its own is decided by the ordinary policies, as before.
        $eventResult = $this->matcher->match($this->resolver->resolve($face->fresh()));
        $this->assertSame('STUDENT_ACTIVE', $eventResult->reasonCode);
        $this->assertSame(AccessDecisionOutcome::ALLOW, $eventResult->decision);
    }

    public function test_match_result_and_vehicle_status_dimensions_must_equal_the_context(): void
    {
        $blocked = $this->gatePolicy([
            'vehicle_status' => VehicleStatus::BLOCKED,
            'decision' => AccessDecisionOutcome::DENY,
            'reason_code' => 'GATE_TEST_BLOCKED',
            'priority' => 1,
        ]);
        $matched = $this->gatePolicy([
            'match_result' => GateMatchResult::MATCHED,
            'decision' => AccessDecisionOutcome::ALLOW,
            'reason_code' => 'GATE_TEST_MATCHED',
        ]);
        $person = $this->student();

        $active = Vehicle::factory()->create(['status' => VehicleStatus::ACTIVE]);
        $this->link($person, $active);
        $ok = $this->pass(GateMatchResult::MATCHED, $this->event('FACE_RECOGNIZED', $person), $this->event('PLATE_RECOGNIZED'), $active);
        $this->assertSame($matched->id, $this->matcher->match($this->resolver->resolvePass($ok))->matchedPolicyId);

        $bad = Vehicle::factory()->create(['status' => VehicleStatus::BLOCKED]);
        $this->link($person, $bad);
        $stopped = $this->pass(GateMatchResult::MATCHED, $this->event('FACE_RECOGNIZED', $person), $this->event('PLATE_RECOGNIZED'), $bad);
        $this->assertSame($blocked->id, $this->matcher->match($this->resolver->resolvePass($stopped))->matchedPolicyId);

        // No vehicle: neither a vehicle_status policy nor a MATCHED policy applies.
        $faceOnly = $this->pass(GateMatchResult::FACE_ONLY, $this->event('FACE_RECOGNIZED', $person), null);
        $result = $this->matcher->match($this->resolver->resolvePass($faceOnly));
        $this->assertNotContains($result->matchedPolicyId, [$blocked->id, $matched->id]);
    }

    public function test_gate_specific_policy_applies_to_its_gate_only(): void
    {
        $other = Gate::factory()->create(['site_id' => $this->gate->site_id, 'zone_id' => $this->gate->zone_id]);
        $generic = $this->gatePolicy([
            'match_result' => GateMatchResult::MISMATCH,
            'decision' => AccessDecisionOutcome::ALLOW,
            'reason_code' => 'GATE_TEST_MISMATCH_ALLOW',
            'priority' => 50,
        ]);
        $strict = $this->gatePolicy([
            'gate_id' => $this->gate->id,
            'match_result' => GateMatchResult::MISMATCH,
            'decision' => AccessDecisionOutcome::REVIEW,
            'reason_code' => 'GATE_TEST_MISMATCH_REVIEW',
            'priority' => 40,
        ]);
        $person = $this->student();
        $vehicle = Vehicle::factory()->create();

        $here = $this->pass(GateMatchResult::MISMATCH, $this->event('FACE_RECOGNIZED', $person), $this->event('PLATE_RECOGNIZED'), $vehicle);
        $there = $this->pass(GateMatchResult::MISMATCH, $this->event('FACE_RECOGNIZED', $person), $this->event('PLATE_RECOGNIZED'), $vehicle, $other);

        $this->assertSame($strict->id, $this->matcher->match($this->resolver->resolvePass($here))->matchedPolicyId);
        $this->assertSame($generic->id, $this->matcher->match($this->resolver->resolvePass($there))->matchedPolicyId);
    }

    public function test_with_only_the_default_policies_a_plate_alone_is_never_allowed(): void
    {
        $this->seed(AccessPolicySeeder::class);
        $owner = $this->student();
        $vehicle = Vehicle::factory()->create();
        $this->link($owner, $vehicle);
        $pass = $this->pass(GateMatchResult::PLATE_ONLY, null, $this->event('PLATE_RECOGNIZED'), $vehicle);

        $result = $this->matcher->match($this->resolver->resolvePass($pass));

        $this->assertSame(AccessDecisionOutcome::REVIEW, $result->decision);
        $this->assertSame('NO_MATCHING_POLICY', $result->reasonCode);
        $this->assertSame(DecisionSource::SYSTEM, $result->decisionSource);
    }

    public function test_resolving_and_matching_a_pass_writes_nothing(): void
    {
        $this->seed(AccessPolicySeeder::class);
        $person = $this->student();
        $vehicle = Vehicle::factory()->create();
        $this->link($person, $vehicle);
        $face = $this->event('FACE_RECOGNIZED', $person);
        $plate = $this->event('PLATE_RECOGNIZED');
        $pass = $this->pass(GateMatchResult::MATCHED, $face, $plate, $vehicle);
        $before = [$pass->fresh()->toArray(), $face->fresh()->toArray(), $plate->fresh()->toArray()];

        $this->matcher->match($this->resolver->resolvePass($pass->fresh()));

        $this->assertSame(0, AccessDecision::count());
        $this->assertSame($before, [$pass->fresh()->toArray(), $face->fresh()->toArray(), $plate->fresh()->toArray()]);
    }

    public function test_a_gate_named_by_a_policy_cannot_be_hard_deleted(): void
    {
        $this->gatePolicy(['gate_id' => $this->gate->id]);

        try {
            DB::transaction(fn () => DB::table('gates')->where('id', $this->gate->id)->delete());
            $this->fail('Hard-deleting the gate must not turn its policy into one for every gate');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
