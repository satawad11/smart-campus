<?php

namespace Tests\Feature;

use App\Enums\AccessDecisionOutcome;
use App\Enums\DecisionSource;
use App\Models\AccessEvent;
use App\Models\AccessPolicy;
use App\Models\Person;
use App\Models\Site;
use App\Models\StudentProfile;
use App\Models\Zone;
use App\Services\Event\AccessContext;
use App\Services\Event\AccessContextResolver;
use App\Services\Event\AccessPolicyMatcher;
use Database\Seeders\AccessPolicySeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EVT-006 — AccessContext resolution and the access-policy matcher.
 *
 * Pure evaluation: neither AccessContextResolver nor AccessPolicyMatcher
 * writes to the database. Persisting the result as an AccessDecision is
 * EVT-007, not built yet.
 */
class AccessPolicyMatcherTest extends TestCase
{
    use RefreshDatabase;

    private AccessPolicyMatcher $matcher;

    private AccessContextResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = app(AccessPolicyMatcher::class);
        $this->resolver = app(AccessContextResolver::class);
    }

    // ================= AccessContextResolver =================

    public function test_resolves_student_subject_type_and_status_from_the_persons_profile(): void
    {
        $person = Person::factory()->create();
        StudentProfile::factory()->for($person)->create(['academic_status' => 'SUSPENDED']);
        $event = AccessEvent::factory()->create(['person_id' => $person->id]);

        $context = $this->resolver->resolve($event->fresh());

        $this->assertSame('STUDENT', $context->subjectType);
        $this->assertSame('SUSPENDED', $context->subjectStatus);
    }

    public function test_resolves_null_subject_when_person_has_no_student_profile(): void
    {
        $person = Person::factory()->create();
        $event = AccessEvent::factory()->create(['person_id' => $person->id]);

        $context = $this->resolver->resolve($event->fresh());

        $this->assertNull($context->subjectType);
        $this->assertNull($context->subjectStatus);
    }

    public function test_resolves_null_subject_when_no_person_at_all(): void
    {
        $event = AccessEvent::factory()->create(['person_id' => null]);

        $context = $this->resolver->resolve($event->fresh());

        $this->assertNull($context->subjectType);
        $this->assertNull($context->subjectStatus);
    }

    public function test_context_carries_the_events_own_site_zone_and_event_type(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();
        $event = AccessEvent::factory()->create([
            'site_id' => $site->id,
            'zone_id' => $zone->id,
            'event_type' => 'FACE_RECOGNIZED',
        ]);

        $context = $this->resolver->resolve($event->fresh());

        $this->assertSame($site->id, $context->siteId);
        $this->assertSame($zone->id, $context->zoneId);
        $this->assertSame('FACE_RECOGNIZED', $context->eventType);
    }

    // ================= AccessPolicyMatcher: seeded defaults =================

    public function test_student_active_matches_the_seeded_allow_policy(): void
    {
        $this->seed(EventTypeSeeder::class);
        $this->seed(AccessPolicySeeder::class);

        $result = $this->matcher->match(new AccessContext(
            subjectType: 'STUDENT',
            subjectStatus: 'ACTIVE',
            siteId: 1,
            zoneId: null,
            eventType: 'FACE_RECOGNIZED',
        ));

        $this->assertSame(AccessDecisionOutcome::ALLOW, $result->decision);
        $this->assertSame('STUDENT_ACTIVE', $result->reasonCode);
        $this->assertSame(DecisionSource::POLICY, $result->decisionSource);
        $this->assertNotNull($result->matchedPolicyId);
    }

    public function test_student_expelled_matches_the_seeded_deny_policy(): void
    {
        $this->seed(EventTypeSeeder::class);
        $this->seed(AccessPolicySeeder::class);

        $result = $this->matcher->match(new AccessContext(
            subjectType: 'STUDENT',
            subjectStatus: 'EXPELLED',
            siteId: 1,
            zoneId: null,
            eventType: 'FACE_RECOGNIZED',
        ));

        $this->assertSame(AccessDecisionOutcome::DENY, $result->decision);
        $this->assertSame('STUDENT_EXPELLED', $result->reasonCode);
    }

    public function test_liveness_failed_matches_regardless_of_subject(): void
    {
        $this->seed(EventTypeSeeder::class);
        $this->seed(AccessPolicySeeder::class);

        $result = $this->matcher->match(new AccessContext(
            subjectType: 'STUDENT',
            subjectStatus: 'ACTIVE',
            siteId: 1,
            zoneId: null,
            eventType: 'LIVENESS_FAILED',
        ));

        $this->assertSame(AccessDecisionOutcome::REVIEW, $result->decision);
        $this->assertSame('LIVENESS_FAILED', $result->reasonCode);
    }

    public function test_liveness_failed_takes_priority_over_student_active(): void
    {
        // A LIVENESS_FAILED event for an ACTIVE student must still REVIEW,
        // not ALLOW — priority 10 vs 200 (see AccessPolicySeeder).
        $this->seed(EventTypeSeeder::class);
        $this->seed(AccessPolicySeeder::class);

        $result = $this->matcher->match(new AccessContext(
            subjectType: 'STUDENT',
            subjectStatus: 'ACTIVE',
            siteId: 1,
            zoneId: null,
            eventType: 'LIVENESS_FAILED',
        ));

        $this->assertSame(AccessDecisionOutcome::REVIEW, $result->decision);
    }

    public function test_unrecognized_person_matches_the_seeded_unknown_policy(): void
    {
        $this->seed(EventTypeSeeder::class);
        $this->seed(AccessPolicySeeder::class);

        $result = $this->matcher->match(new AccessContext(
            subjectType: null,
            subjectStatus: null,
            siteId: 1,
            zoneId: null,
            eventType: 'UNKNOWN_PERSON',
        ));

        $this->assertSame(AccessDecisionOutcome::UNKNOWN, $result->decision);
        $this->assertSame('RECOGNITION_UNKNOWN', $result->reasonCode);
    }

    // ================= AccessPolicyMatcher: fail-closed default =================

    public function test_no_matching_policy_fails_closed_to_review(): void
    {
        // No seeded policies at all — nothing configured.
        $result = $this->matcher->match(new AccessContext(
            subjectType: 'STUDENT',
            subjectStatus: 'ACTIVE',
            siteId: 1,
            zoneId: null,
            eventType: 'FACE_RECOGNIZED',
        ));

        $this->assertSame(AccessDecisionOutcome::REVIEW, $result->decision);
        $this->assertSame('NO_MATCHING_POLICY', $result->reasonCode);
        $this->assertSame(DecisionSource::SYSTEM, $result->decisionSource);
        $this->assertNull($result->matchedPolicyId);
    }

    public function test_a_subject_type_with_no_configured_policy_fails_closed(): void
    {
        $this->seed(EventTypeSeeder::class);
        $this->seed(AccessPolicySeeder::class);

        // No STAFF policies exist at all yet (locked domain).
        $result = $this->matcher->match(new AccessContext(
            subjectType: 'STAFF',
            subjectStatus: 'ACTIVE',
            siteId: 1,
            zoneId: null,
            eventType: 'FACE_RECOGNIZED',
        ));

        $this->assertSame(AccessDecisionOutcome::REVIEW, $result->decision);
        $this->assertSame('NO_MATCHING_POLICY', $result->reasonCode);
    }

    // ================= AccessPolicyMatcher: priority / scoping =================

    public function test_lower_priority_value_wins_when_multiple_policies_match(): void
    {
        $site = Site::factory()->create();

        AccessPolicy::factory()->create([
            'subject_type' => 'STUDENT',
            'subject_status' => 'ACTIVE',
            'site_id' => null,
            'decision' => 'ALLOW',
            'reason_code' => 'GENERIC_ALLOW',
            'priority' => 200,
        ]);
        AccessPolicy::factory()->create([
            'subject_type' => 'STUDENT',
            'subject_status' => 'ACTIVE',
            'site_id' => $site->id,
            'decision' => 'REVIEW',
            'reason_code' => 'SITE_SPECIFIC_REVIEW',
            'priority' => 10,
        ]);

        $result = $this->matcher->match(new AccessContext(
            subjectType: 'STUDENT',
            subjectStatus: 'ACTIVE',
            siteId: $site->id,
            zoneId: null,
            eventType: 'FACE_RECOGNIZED',
        ));

        $this->assertSame('SITE_SPECIFIC_REVIEW', $result->reasonCode);
    }

    public function test_a_disabled_policy_is_never_matched(): void
    {
        AccessPolicy::factory()->create([
            'subject_type' => 'STUDENT',
            'subject_status' => 'ACTIVE',
            'decision' => 'DENY',
            'reason_code' => 'SHOULD_NOT_MATCH',
            'enabled' => false,
        ]);

        $result = $this->matcher->match(new AccessContext(
            subjectType: 'STUDENT',
            subjectStatus: 'ACTIVE',
            siteId: 1,
            zoneId: null,
            eventType: 'FACE_RECOGNIZED',
        ));

        $this->assertSame('NO_MATCHING_POLICY', $result->reasonCode);
    }

    public function test_a_policy_scoped_to_a_different_site_does_not_match(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();

        AccessPolicy::factory()->create([
            'subject_type' => 'STUDENT',
            'subject_status' => 'ACTIVE',
            'site_id' => $siteA->id,
            'decision' => 'DENY',
            'reason_code' => 'SITE_A_ONLY',
        ]);

        $result = $this->matcher->match(new AccessContext(
            subjectType: 'STUDENT',
            subjectStatus: 'ACTIVE',
            siteId: $siteB->id,
            zoneId: null,
            eventType: 'FACE_RECOGNIZED',
        ));

        $this->assertSame('NO_MATCHING_POLICY', $result->reasonCode);
    }

    public function test_an_expired_policy_does_not_match(): void
    {
        AccessPolicy::factory()->create([
            'subject_type' => 'STUDENT',
            'subject_status' => 'ACTIVE',
            'decision' => 'DENY',
            'reason_code' => 'EXPIRED_POLICY',
            'valid_until' => now()->subDay(),
        ]);

        $result = $this->matcher->match(new AccessContext(
            subjectType: 'STUDENT',
            subjectStatus: 'ACTIVE',
            siteId: 1,
            zoneId: null,
            eventType: 'FACE_RECOGNIZED',
        ));

        $this->assertSame('NO_MATCHING_POLICY', $result->reasonCode);
    }

    public function test_a_not_yet_valid_policy_does_not_match(): void
    {
        AccessPolicy::factory()->create([
            'subject_type' => 'STUDENT',
            'subject_status' => 'ACTIVE',
            'decision' => 'DENY',
            'reason_code' => 'FUTURE_POLICY',
            'valid_from' => now()->addDay(),
        ]);

        $result = $this->matcher->match(new AccessContext(
            subjectType: 'STUDENT',
            subjectStatus: 'ACTIVE',
            siteId: 1,
            zoneId: null,
            eventType: 'FACE_RECOGNIZED',
        ));

        $this->assertSame('NO_MATCHING_POLICY', $result->reasonCode);
    }

    public function test_matcher_never_produces_a_decision_outside_the_canonical_set(): void
    {
        $this->seed(EventTypeSeeder::class);
        $this->seed(AccessPolicySeeder::class);

        foreach (['ACTIVE', 'LEAVE', 'SUSPENDED', 'WITHDRAWN', 'GRADUATED', 'EXPELLED'] as $status) {
            $result = $this->matcher->match(new AccessContext(
                subjectType: 'STUDENT',
                subjectStatus: $status,
                siteId: 1,
                zoneId: null,
                eventType: 'FACE_RECOGNIZED',
            ));

            $this->assertContains($result->decision, AccessDecisionOutcome::cases());
        }
    }
}
