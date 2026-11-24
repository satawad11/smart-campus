<?php

namespace Tests\Feature;

use App\Enums\AccessDecisionOutcome;
use App\Enums\GateMatchResult;
use App\Enums\PersonStatus;
use App\Enums\VehicleStatus;
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
use App\Services\Event\AccessPolicyMatchResult;
use Database\Seeders\AccessPolicySeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GATE-006 (ACR-VEH-01 decision D2): the seeded FACE_PRIMARY gate policies, evaluated end to end
 * through AccessContextResolver::resolvePass and the policy matcher.
 */
class FacePrimaryGatePolicyTest extends TestCase
{
    use RefreshDatabase;

    private Gate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EventTypeSeeder::class);
        $this->seed(AccessPolicySeeder::class);
        $this->gate = Gate::factory()->create();
    }

    private function student(string $status = 'ACTIVE'): Person
    {
        $person = Person::factory()->create();
        StudentProfile::factory()->for($person)->create(['academic_status' => $status]);

        return $person;
    }

    private function vehicle(?Person $owner = null, VehicleStatus $status = VehicleStatus::ACTIVE): Vehicle
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

    /** Decide a pass with the given face type/person and plate type/vehicle. */
    private function decide(GateMatchResult $result, ?string $faceType, ?Person $person, ?string $plateType, ?Vehicle $vehicle, ?Gate $gate = null): AccessPolicyMatchResult
    {
        $face = $faceType === null ? null : $this->event($faceType, $person);
        $plate = $plateType === null ? null : $this->event($plateType);

        $pass = GatePass::create([
            'gate_id' => ($gate ?? $this->gate)->id,
            'face_event_id' => $face?->id,
            'plate_event_id' => $plate?->id,
            'person_id' => $face?->person_id,
            'vehicle_id' => $vehicle?->id,
            'match_result' => $result,
            'occurred_at' => ($face ?? $plate)->occurred_at,
        ]);

        return app(AccessPolicyMatcher::class)->match(app(AccessContextResolver::class)->resolvePass($pass));
    }

    private function assertDecision(AccessDecisionOutcome $decision, string $reason, AccessPolicyMatchResult $result): void
    {
        $this->assertSame([$decision, $reason], [$result->decision, $result->reasonCode]);
    }

    public function test_seeder_creates_the_gate_policies_and_none_of_them_allows(): void
    {
        $gatePolicies = AccessPolicy::whereNotNull('gate_mode')->get()->keyBy('name');

        $this->assertSame([
            'Gate Face Unknown -> Review',
            'Gate Liveness Failed -> Review',
            'Gate Plate Only -> Review',
            'Gate Plate Unknown -> Review',
            'Gate Vehicle Blocked -> Deny',
            'Gate Vehicle Mismatch -> Review',
        ], $gatePolicies->keys()->sort()->values()->all());
        foreach ($gatePolicies as $policy) {
            $this->assertNotSame(AccessDecisionOutcome::ALLOW, $policy->decision, "{$policy->name} must never allow");
            $this->assertNull($policy->subject_type);
            $this->assertNull($policy->gate_id);
        }
        $this->assertFalse($gatePolicies['Gate Vehicle Mismatch -> Review']->enabled);
        $this->assertFalse($gatePolicies['Gate Plate Unknown -> Review']->enabled);
    }

    public function test_recognised_entitled_face_is_allowed_whatever_the_plate_says(): void
    {
        $person = $this->student();

        // Own car.
        $this->assertDecision(AccessDecisionOutcome::ALLOW, 'STUDENT_ACTIVE',
            $this->decide(GateMatchResult::MATCHED, 'FACE_RECOGNIZED', $person, 'PLATE_RECOGNIZED', $this->vehicle($person)));
        // Someone else's car: ALLOW with the note carried by match_result (D2 default).
        $this->assertDecision(AccessDecisionOutcome::ALLOW, 'STUDENT_ACTIVE',
            $this->decide(GateMatchResult::MISMATCH, 'FACE_RECOGNIZED', $person, 'PLATE_RECOGNIZED', $this->vehicle(Person::factory()->create())));
        // Plate not read.
        $this->assertDecision(AccessDecisionOutcome::ALLOW, 'STUDENT_ACTIVE',
            $this->decide(GateMatchResult::UNKNOWN, 'FACE_RECOGNIZED', $person, 'PLATE_UNKNOWN', null));
        // No plate event at all.
        $this->assertDecision(AccessDecisionOutcome::ALLOW, 'STUDENT_ACTIVE',
            $this->decide(GateMatchResult::FACE_ONLY, 'FACE_RECOGNIZED', $person, null, null));
    }

    public function test_the_persons_own_restriction_still_applies_at_the_gate(): void
    {
        $expelled = $this->student('EXPELLED');
        $this->assertDecision(AccessDecisionOutcome::DENY, 'STUDENT_EXPELLED',
            $this->decide(GateMatchResult::MATCHED, 'FACE_RECOGNIZED', $expelled, 'PLATE_RECOGNIZED', $this->vehicle($expelled)));

        $inactive = $this->student();
        $inactive->forceFill(['status' => PersonStatus::INACTIVE])->save();
        $this->assertDecision(AccessDecisionOutcome::REVIEW, 'PERSON_RECORD_INACTIVE',
            $this->decide(GateMatchResult::MATCHED, 'FACE_RECOGNIZED', $inactive, 'PLATE_RECOGNIZED', $this->vehicle($inactive)));
    }

    public function test_unknown_face_goes_to_review_with_or_without_a_known_plate(): void
    {
        $owner = $this->student();

        $this->assertDecision(AccessDecisionOutcome::REVIEW, 'GATE_FACE_UNKNOWN',
            $this->decide(GateMatchResult::UNKNOWN, 'UNKNOWN_PERSON', null, 'PLATE_RECOGNIZED', $this->vehicle($owner)));
        $this->assertDecision(AccessDecisionOutcome::REVIEW, 'GATE_FACE_UNKNOWN',
            $this->decide(GateMatchResult::UNKNOWN, 'UNKNOWN_PERSON', null, 'PLATE_UNKNOWN', null));
        $this->assertDecision(AccessDecisionOutcome::REVIEW, 'GATE_FACE_UNKNOWN',
            $this->decide(GateMatchResult::FACE_ONLY, 'UNKNOWN_PERSON', null, null, null));
        $this->assertDecision(AccessDecisionOutcome::REVIEW, 'GATE_LIVENESS_FAILED',
            $this->decide(GateMatchResult::UNKNOWN, 'LIVENESS_FAILED', $owner, 'PLATE_RECOGNIZED', $this->vehicle($owner)));
    }

    public function test_a_plate_alone_never_lets_anyone_in(): void
    {
        $owner = $this->student();

        $this->assertDecision(AccessDecisionOutcome::REVIEW, 'GATE_PLATE_ONLY',
            $this->decide(GateMatchResult::PLATE_ONLY, null, null, 'PLATE_RECOGNIZED', $this->vehicle($owner)));
        $this->assertDecision(AccessDecisionOutcome::REVIEW, 'GATE_PLATE_ONLY',
            $this->decide(GateMatchResult::PLATE_ONLY, null, null, 'PLATE_UNKNOWN', null));
    }

    public function test_a_blocked_vehicle_is_denied_whoever_drives_it(): void
    {
        $person = $this->student();

        $this->assertDecision(AccessDecisionOutcome::DENY, 'GATE_VEHICLE_BLOCKED',
            $this->decide(GateMatchResult::MATCHED, 'FACE_RECOGNIZED', $person, 'PLATE_RECOGNIZED', $this->vehicle($person, VehicleStatus::BLOCKED)));
        $this->assertDecision(AccessDecisionOutcome::DENY, 'GATE_VEHICLE_BLOCKED',
            $this->decide(GateMatchResult::PLATE_ONLY, null, null, 'PLATE_RECOGNIZED', $this->vehicle(null, VehicleStatus::BLOCKED)));
        $this->assertDecision(AccessDecisionOutcome::DENY, 'GATE_VEHICLE_BLOCKED',
            $this->decide(GateMatchResult::UNKNOWN, 'UNKNOWN_PERSON', null, 'PLATE_RECOGNIZED', $this->vehicle(null, VehicleStatus::BLOCKED)));
    }

    public function test_ordinary_face_events_are_decided_exactly_as_before(): void
    {
        $matcher = app(AccessPolicyMatcher::class);
        $resolver = app(AccessContextResolver::class);

        $unknown = $matcher->match($resolver->resolve($this->event('UNKNOWN_PERSON')->fresh()));
        $this->assertDecision(AccessDecisionOutcome::UNKNOWN, 'RECOGNITION_UNKNOWN', $unknown);

        $liveness = $matcher->match($resolver->resolve($this->event('LIVENESS_FAILED')->fresh()));
        $this->assertDecision(AccessDecisionOutcome::REVIEW, 'LIVENESS_FAILED', $liveness);
    }

    public function test_enabling_the_mismatch_template_only_makes_decisions_stricter(): void
    {
        AccessPolicy::where('name', 'Gate Vehicle Mismatch -> Review')->update(['enabled' => true]);
        $active = $this->student();
        $expelled = $this->student('EXPELLED');

        $this->assertDecision(AccessDecisionOutcome::REVIEW, 'GATE_VEHICLE_MISMATCH',
            $this->decide(GateMatchResult::MISMATCH, 'FACE_RECOGNIZED', $active, 'PLATE_RECOGNIZED', $this->vehicle(Person::factory()->create())));
        // A stricter personal rule still wins over the template.
        $this->assertDecision(AccessDecisionOutcome::DENY, 'STUDENT_EXPELLED',
            $this->decide(GateMatchResult::MISMATCH, 'FACE_RECOGNIZED', $expelled, 'PLATE_RECOGNIZED', $this->vehicle(Person::factory()->create())));
        // Own car is unaffected.
        $this->assertDecision(AccessDecisionOutcome::ALLOW, 'STUDENT_ACTIVE',
            $this->decide(GateMatchResult::MATCHED, 'FACE_RECOGNIZED', $active, 'PLATE_RECOGNIZED', $this->vehicle($active)));
    }

    public function test_plate_unknown_template_reviews_a_recognised_face_with_an_unread_plate(): void
    {
        AccessPolicy::where('name', 'Gate Plate Unknown -> Review')->update(['enabled' => true]);
        $person = $this->student();

        $this->assertDecision(AccessDecisionOutcome::REVIEW, 'GATE_PLATE_UNKNOWN',
            $this->decide(GateMatchResult::UNKNOWN, 'FACE_RECOGNIZED', $person, 'PLATE_UNKNOWN', null));
        // An unknown face keeps its own, earlier review reason.
        $this->assertDecision(AccessDecisionOutcome::REVIEW, 'GATE_FACE_UNKNOWN',
            $this->decide(GateMatchResult::UNKNOWN, 'UNKNOWN_PERSON', null, 'PLATE_UNKNOWN', null));
    }

    public function test_a_template_copied_for_one_gate_applies_to_that_gate_only(): void
    {
        $template = AccessPolicy::where('name', 'Gate Vehicle Mismatch -> Review')->firstOrFail();
        $copy = $template->replicate(['uuid']);
        $copy->fill(['name' => 'Main gate mismatch -> Review', 'gate_id' => $this->gate->id, 'enabled' => true])->save();
        $other = Gate::factory()->create(['site_id' => $this->gate->site_id, 'zone_id' => $this->gate->zone_id]);
        $person = $this->student();

        $this->assertDecision(AccessDecisionOutcome::REVIEW, 'GATE_VEHICLE_MISMATCH',
            $this->decide(GateMatchResult::MISMATCH, 'FACE_RECOGNIZED', $person, 'PLATE_RECOGNIZED', $this->vehicle(Person::factory()->create())));
        $this->assertDecision(AccessDecisionOutcome::ALLOW, 'STUDENT_ACTIVE',
            $this->decide(GateMatchResult::MISMATCH, 'FACE_RECOGNIZED', $person, 'PLATE_RECOGNIZED', $this->vehicle(Person::factory()->create()), $other));
    }

    public function test_reseeding_keeps_an_enabled_template_enabled_and_restores_the_core_rules(): void
    {
        AccessPolicy::where('name', 'Gate Vehicle Mismatch -> Review')->update(['enabled' => true]);
        AccessPolicy::where('name', 'Gate Vehicle Blocked -> Deny')->update(['decision' => 'REVIEW']);

        $this->seed(AccessPolicySeeder::class);

        $this->assertTrue(AccessPolicy::where('name', 'Gate Vehicle Mismatch -> Review')->value('enabled'));
        $this->assertSame('DENY', AccessPolicy::where('name', 'Gate Vehicle Blocked -> Deny')->first()->decision->value);
        $this->assertSame(6, AccessPolicy::whereNotNull('gate_mode')->count());
    }

    public function test_migration_adds_missing_gate_policies_to_an_existing_install_without_overwriting(): void
    {
        $migration = require database_path('migrations/2026_11_19_900005_seed_face_primary_gate_policies.php');
        // An install seeded before GATE-006: no gate policies, except one an admin already edited.
        AccessPolicy::whereNotNull('gate_mode')->where('name', '!=', 'Gate Plate Only -> Review')->delete();
        AccessPolicy::where('name', 'Gate Plate Only -> Review')->update(['priority' => 7]);

        $migration->up();
        $migration->up();

        $this->assertSame(6, AccessPolicy::whereNotNull('gate_mode')->count());
        $this->assertSame(7, AccessPolicy::where('name', 'Gate Plate Only -> Review')->value('priority'));
        $this->assertDecision(AccessDecisionOutcome::DENY, 'GATE_VEHICLE_BLOCKED',
            $this->decide(GateMatchResult::PLATE_ONLY, null, null, 'PLATE_RECOGNIZED', $this->vehicle(null, VehicleStatus::BLOCKED)));
    }

    public function test_migration_does_nothing_on_a_fresh_install(): void
    {
        $migration = require database_path('migrations/2026_11_19_900005_seed_face_primary_gate_policies.php');
        AccessPolicy::query()->delete();

        $migration->up();

        $this->assertSame(0, AccessPolicy::count());
    }
}
