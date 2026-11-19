<?php

namespace Tests\Feature;

use App\Enums\PersonType;
use App\Enums\StaffEmploymentStatus;
use App\Enums\VisitStatus;
use App\Models\AccessEvent;
use App\Models\AccessPolicy;
use App\Models\GuardianLink;
use App\Models\Person;
use App\Models\Site;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\Visit;
use App\Models\VisitorProfile;
use App\Services\Event\AccessContext;
use App\Services\Event\AccessContextResolver;
use App\Services\Event\AccessPolicyMatcher;
use App\Services\System\SystemSettingService;
use Database\Seeders\AccessPolicySeeder;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeopleAccessContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemSettingSeeder::class);
    }

    private function enableVisitorFeature(): void
    {
        config(['features.locked_kill_switches.visitor' => null]);
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.visitor' => true]);
    }

    public function test_resolves_staff_type_and_employment_status(): void
    {
        $person = Person::factory()->create(['person_type' => PersonType::TEACHER]);
        StaffProfile::factory()->for($person)->create(['employment_status' => StaffEmploymentStatus::SUSPENDED]);
        $context = app(AccessContextResolver::class)->resolve(AccessEvent::factory()->create(['person_id' => $person->id]));

        $this->assertSame('STAFF', $context->subjectType);
        $this->assertSame('SUSPENDED', $context->subjectStatus);
    }

    public function test_inactive_person_record_overrides_active_student_or_staff_profile(): void
    {
        $student = Person::factory()->create(['status' => 'INACTIVE']);
        StudentProfile::factory()->for($student)->create(['academic_status' => 'ACTIVE']);
        $staff = Person::factory()->create(['person_type' => PersonType::STAFF, 'status' => 'ARCHIVED']);
        StaffProfile::factory()->for($staff)->create(['employment_status' => 'ACTIVE']);
        $resolver = app(AccessContextResolver::class);

        $studentContext = $resolver->resolve(AccessEvent::factory()->create(['person_id' => $student->id]));
        $staffContext = $resolver->resolve(AccessEvent::factory()->create(['person_id' => $staff->id]));

        $this->assertSame('STUDENT', $studentContext->subjectType);
        $this->assertSame('PERSON_INACTIVE', $studentContext->subjectStatus);
        $this->assertSame('STAFF', $staffContext->subjectType);
        $this->assertSame('PERSON_INACTIVE', $staffContext->subjectStatus);
    }

    public function test_visitor_is_active_only_for_a_current_visit_at_the_event_site(): void
    {
        $this->enableVisitorFeature();
        $person = Person::factory()->create(['person_type' => PersonType::CONTRACTOR]);
        VisitorProfile::factory()->for($person)->create();
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        Visit::factory()->create(['visitor_person_id' => $person->id, 'site_id' => $otherSite->id, 'status' => VisitStatus::SCHEDULED]);

        $resolver = app(AccessContextResolver::class);
        $event = AccessEvent::factory()->create(['person_id' => $person->id, 'site_id' => $site->id]);
        $context = $resolver->resolve($event);
        $this->assertSame('VISITOR', $context->subjectType);
        $this->assertSame('NO_ACTIVE_VISIT', $context->subjectStatus);

        Visit::factory()->create([
            'visitor_person_id' => $person->id,
            'site_id' => $site->id,
            'status' => VisitStatus::SCHEDULED,
            'valid_from' => now()->subMinute(),
            'valid_until' => now()->addMinute(),
        ]);
        $this->assertSame('VISIT_ACTIVE', $resolver->resolve($event)->subjectStatus);
    }

    public function test_checked_in_visit_remains_active_until_checkout_even_after_window(): void
    {
        $this->enableVisitorFeature();
        $person = Person::factory()->create(['person_type' => PersonType::VISITOR]);
        VisitorProfile::factory()->for($person)->create();
        $site = Site::factory()->create();
        Visit::factory()->create([
            'visitor_person_id' => $person->id,
            'site_id' => $site->id,
            'status' => VisitStatus::CHECKED_IN,
            'valid_from' => now()->subHours(2),
            'valid_until' => now()->subHour(),
        ]);
        $event = AccessEvent::factory()->create(['person_id' => $person->id, 'site_id' => $site->id]);

        $this->assertSame('VISIT_ACTIVE', app(AccessContextResolver::class)->resolve($event)->subjectStatus);
    }

    public function test_visitor_feature_kill_switch_prevents_an_active_visit_from_resolving_to_allow_subject(): void
    {
        $this->enableVisitorFeature();
        $person = Person::factory()->create(['person_type' => PersonType::VISITOR]);
        VisitorProfile::factory()->for($person)->create();
        $site = Site::factory()->create();
        Visit::factory()->create(['visitor_person_id' => $person->id, 'site_id' => $site->id]);
        $event = AccessEvent::factory()->create(['person_id' => $person->id, 'site_id' => $site->id]);
        config(['features.locked_kill_switches.visitor' => false]);

        $context = app(AccessContextResolver::class)->resolve($event);
        $this->assertSame('VISITOR', $context->subjectType);
        $this->assertSame('NO_ACTIVE_VISIT', $context->subjectStatus);
        $this->seed(EventTypeSeeder::class);
        $this->seed(AccessPolicySeeder::class);
        $decision = app(AccessPolicyMatcher::class)->match($context);
        $this->assertSame('REVIEW', $decision->decision->value);
        $this->assertSame('VISITOR_NO_ACTIVE_VISIT', $decision->reasonCode);
    }

    public function test_parent_is_active_only_while_linked_to_an_active_student(): void
    {
        $parent = Person::factory()->create(['person_type' => PersonType::PARENT]);
        $student = StudentProfile::factory()->create(['academic_status' => 'ACTIVE'])->person;
        GuardianLink::factory()->create(['guardian_person_id' => $parent->id, 'student_person_id' => $student->id]);
        $event = AccessEvent::factory()->create(['person_id' => $parent->id]);
        $resolver = app(AccessContextResolver::class);

        $this->assertSame('PARENT', $resolver->resolve($event)->subjectType);
        $this->assertSame('ACTIVE', $resolver->resolve($event)->subjectStatus);
        $student->studentProfile()->update(['academic_status' => 'GRADUATED']);
        $this->assertSame('NO_ACTIVE_STUDENT', $resolver->resolve($event)->subjectStatus);
    }

    public function test_seeded_people_policies_are_idempotent_and_fail_to_review_for_exception_statuses(): void
    {
        $seeder = new AccessPolicySeeder;
        $this->seed(EventTypeSeeder::class);
        $seeder->run();
        $seeder->run();
        $matcher = app(AccessPolicyMatcher::class);

        $this->assertSame('ALLOW', $matcher->match(new AccessContext('STAFF', 'ACTIVE', 1, null, 'FACE_RECOGNIZED'))->decision->value);
        $this->assertSame('REVIEW', $matcher->match(new AccessContext('STAFF', 'RESIGNED', 1, null, 'FACE_RECOGNIZED'))->decision->value);
        $this->assertSame('REVIEW', $matcher->match(new AccessContext('VISITOR', 'VISIT_ACTIVE', 1, null, 'FACE_RECOGNIZED'))->decision->value);
        $this->assertSame('REVIEW', $matcher->match(new AccessContext('STUDENT', 'PERSON_INACTIVE', 1, null, 'FACE_RECOGNIZED'))->decision->value);
        $this->assertSame('REVIEW', $matcher->match(new AccessContext('STAFF', 'PERSON_INACTIVE', 1, null, 'FACE_RECOGNIZED'))->decision->value);
        $this->assertSame('REVIEW', $matcher->match(new AccessContext('VISITOR', 'NO_ACTIVE_VISIT', 1, null, 'FACE_RECOGNIZED'))->decision->value);
        $this->assertSame('REVIEW', $matcher->match(new AccessContext('PARENT', 'ACTIVE', 1, null, 'FACE_RECOGNIZED'))->decision->value);
        $this->assertSame('REVIEW', $matcher->match(new AccessContext('PARENT', 'NO_ACTIVE_STUDENT', 1, null, 'FACE_RECOGNIZED'))->decision->value);
        $this->assertDatabaseCount('access_policies', 19);
    }

    public function test_seeder_disables_legacy_active_visitor_and_parent_allow_policies(): void
    {
        $this->seed(EventTypeSeeder::class);
        $legacyVisitor = AccessPolicy::factory()->create([
            'name' => 'Visitor Active Visit -> Allow',
            'subject_type' => 'VISITOR',
            'subject_status' => 'VISIT_ACTIVE',
            'decision' => 'ALLOW',
            'reason_code' => 'VISIT_ACTIVE',
            'priority' => 200,
            'enabled' => true,
        ]);
        $legacyParent = AccessPolicy::factory()->create([
            'name' => 'Parent With Active Student -> Allow',
            'subject_type' => 'PARENT',
            'subject_status' => 'ACTIVE',
            'decision' => 'ALLOW',
            'reason_code' => 'PARENT_ACTIVE',
            'priority' => 200,
            'enabled' => true,
        ]);

        (new AccessPolicySeeder)->run();

        $this->assertFalse($legacyVisitor->fresh()->enabled);
        $this->assertFalse($legacyParent->fresh()->enabled);
    }
}
