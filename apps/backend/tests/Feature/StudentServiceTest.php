<?php

namespace Tests\Feature;

use App\Enums\PersonStatus;
use App\Enums\PersonType;
use App\Enums\StatusChangeSource;
use App\Enums\StudentAcademicStatus;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Department;
use App\Models\Person;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\StudentStatusHistory;
use App\Models\User;
use App\Services\Student\StudentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * STD-006 — StudentService domain layer.
 *
 * Service-level tests (no HTTP controllers/routes exist yet; those and their
 * auth/permission tests belong to STD-007/STD-008). Covers creation atomicity,
 * cross-entity academic consistency, the authoritative status-transition
 * transaction (current ↔ history), audit integration, and the Person/User/
 * access-decision separation invariants.
 */
class StudentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): StudentService
    {
        return app(StudentService::class);
    }

    private function actor(): User
    {
        return User::factory()->create(['status' => UserStatus::ACTIVE]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Department $dept, array $overrides = []): array
    {
        return array_merge([
            'person' => [
                'person_code' => 'P'.uniqid(),
                'first_name' => 'Somchai',
                'last_name' => 'Jaidee',
                'display_name' => 'Somchai J.',
            ],
            'student_code' => 'S'.uniqid(),
            'department_uuid' => $dept->uuid,
            'education_level' => 'VOC_CERT',
            'year_level' => 1,
        ], $overrides);
    }

    // ================= Creation =================

    public function test_valid_creation_creates_person_profile_history_and_audit(): void
    {
        $dept = Department::factory()->create();
        $actor = $this->actor();

        $profile = $this->service()->createStudent($this->payload($dept), $actor, null);

        $this->assertInstanceOf(StudentProfile::class, $profile);
        $this->assertDatabaseHas('student_profiles', ['id' => $profile->id, 'department_id' => $dept->id]);

        // Person created, typed STUDENT, record status ACTIVE (not academic status).
        $person = $profile->person;
        $this->assertSame(PersonType::STUDENT, $person->person_type);
        $this->assertSame(PersonStatus::ACTIVE, $person->status);
        $this->assertSame($person->id, $profile->person_id);

        // Exactly one initial history row: old = null, new = ACTIVE, open interval.
        $histories = StudentStatusHistory::where('student_profile_id', $profile->id)->get();
        $this->assertCount(1, $histories);
        $this->assertNull($histories[0]->old_status);
        $this->assertSame(StudentAcademicStatus::ACTIVE, $histories[0]->new_status);
        $this->assertNull($histories[0]->effective_to);
        $this->assertSame($actor->id, $histories[0]->changed_by_user_id);

        // Audit under module=student, subject = profile uuid, no internal ids.
        $log = AuditLog::where('action', 'STUDENT_CREATED')->where('entity_id', $profile->uuid)->first();
        $this->assertNotNull($log);
        $this->assertSame('student', $log->module);
        $this->assertSame('STUDENT', $log->entity_type);
        $this->assertArrayNotHasKey('person_id', $log->new_values);
        $this->assertArrayNotHasKey('department_id', $log->new_values);
        $this->assertArrayNotHasKey('id', $log->new_values);
        $this->assertSame($person->uuid, $log->new_values['person_uuid']);
    }

    public function test_initial_status_defaults_to_active_and_can_be_supplied(): void
    {
        $dept = Department::factory()->create();
        $default = $this->service()->createStudent($this->payload($dept), null, null);
        $this->assertSame(StudentAcademicStatus::ACTIVE, $default->academic_status);

        $leave = $this->service()->createStudent($this->payload($dept, ['academic_status' => 'LEAVE']), null, null);
        $this->assertSame(StudentAcademicStatus::LEAVE, $leave->academic_status);
        $this->assertSame(StudentAcademicStatus::LEAVE, $leave->statusHistories()->sole()->new_status);
    }

    public function test_resolves_relationships_by_uuid_and_stores_internal_ids(): void
    {
        $dept = Department::factory()->create();
        $program = Program::factory()->create(['department_id' => $dept->id, 'education_level' => 'VOC_CERT']);
        $classroom = Classroom::factory()->create(['department_id' => $dept->id, 'program_id' => $program->id]);

        $profile = $this->service()->createStudent($this->payload($dept, [
            'program_uuid' => $program->uuid,
            'classroom_uuid' => $classroom->uuid,
            'education_level' => 'VOC_CERT',
        ]), null, null);

        $this->assertSame($program->id, $profile->program_id);
        $this->assertSame($classroom->id, $profile->classroom_id);
    }

    public function test_duplicate_student_code_rejected(): void
    {
        $dept = Department::factory()->create();
        $this->service()->createStudent($this->payload($dept, ['student_code' => 'DUP-1']), null, null);

        $this->expectException(ApiException::class);
        $this->service()->createStudent($this->payload($dept, ['student_code' => 'DUP-1']), null, null);
    }

    public function test_program_from_other_department_rejected(): void
    {
        $dept = Department::factory()->create();
        $foreign = Program::factory()->create(['department_id' => Department::factory()->create()->id]);

        $this->expectExceptionMessage('program does not belong');
        $this->service()->createStudent($this->payload($dept, ['program_uuid' => $foreign->uuid]), null, null);
    }

    public function test_classroom_from_other_department_rejected(): void
    {
        $dept = Department::factory()->create();
        $foreign = Classroom::factory()->create(['department_id' => Department::factory()->create()->id]);

        $this->expectException(ApiException::class);
        $this->service()->createStudent($this->payload($dept, ['classroom_uuid' => $foreign->uuid]), null, null);
    }

    public function test_classroom_bound_to_other_program_rejected(): void
    {
        $dept = Department::factory()->create();
        $programA = Program::factory()->create(['department_id' => $dept->id, 'education_level' => 'VOC_CERT']);
        $programB = Program::factory()->create(['department_id' => $dept->id, 'education_level' => 'VOC_CERT']);
        // Classroom is bound to program B, but the student is enrolled in program A.
        $classroom = Classroom::factory()->create(['department_id' => $dept->id, 'program_id' => $programB->id]);

        $this->expectException(ApiException::class);
        $this->service()->createStudent($this->payload($dept, [
            'program_uuid' => $programA->uuid,
            'classroom_uuid' => $classroom->uuid,
            'education_level' => 'VOC_CERT',
        ]), null, null);
    }

    public function test_education_level_mismatch_with_program_rejected(): void
    {
        $dept = Department::factory()->create();
        $program = Program::factory()->create(['department_id' => $dept->id, 'education_level' => 'VOC_CERT']);

        $this->expectExceptionMessage('education level');
        $this->service()->createStudent($this->payload($dept, [
            'program_uuid' => $program->uuid,
            'education_level' => 'SHORT_COURSE',
        ]), null, null);
    }

    public function test_inactive_master_data_assignment_rejected(): void
    {
        $inactiveDept = Department::factory()->create(['status' => 'INACTIVE']);
        $this->expectException(ApiException::class);
        $this->service()->createStudent($this->payload($inactiveDept), null, null);
    }

    public function test_inactive_program_assignment_rejected(): void
    {
        $dept = Department::factory()->create();
        $program = Program::factory()->create(['department_id' => $dept->id, 'education_level' => 'VOC_CERT', 'status' => 'INACTIVE']);
        $this->expectException(ApiException::class);
        $this->service()->createStudent($this->payload($dept, ['program_uuid' => $program->uuid, 'education_level' => 'VOC_CERT']), null, null);
    }

    public function test_invalid_academic_status_on_create_rejected(): void
    {
        $dept = Department::factory()->create();
        $this->expectException(ApiException::class);
        $this->service()->createStudent($this->payload($dept, ['academic_status' => 'ALLOW']), null, null);
    }

    public function test_failed_creation_leaves_no_partial_records(): void
    {
        $dept = Department::factory()->create();
        // Seed a Person whose person_code collides with the one we will submit,
        // forcing the Person insert to fail mid-transaction (unique person_code).
        $existing = Person::factory()->create(['person_code' => 'COLLIDE']);
        $personsBefore = Person::count();

        try {
            $this->service()->createStudent($this->payload($dept, [
                'person' => ['person_code' => 'COLLIDE', 'first_name' => 'A', 'last_name' => 'B'],
                'student_code' => 'GHOST-1',
            ]), null, null);
            $this->fail('Expected the creation to fail on duplicate person_code.');
        } catch (\Throwable) {
            // expected
        }

        // Nothing partial committed: no new Person, no profile, no history, no audit.
        $this->assertSame($personsBefore, Person::count());
        $this->assertDatabaseMissing('student_profiles', ['student_code' => 'GHOST-1']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'STUDENT_CREATED', 'new_values->student_code' => 'GHOST-1']);
        $this->assertNotNull($existing->fresh());
    }

    // ================= Status transition =================

    public function test_status_transition_updates_current_and_history_consistently(): void
    {
        $dept = Department::factory()->create();
        $actor = $this->actor();
        $profile = $this->service()->createStudent($this->payload($dept), $actor, null);
        $effective = Carbon::parse('2026-06-01T00:00:00Z');

        $updated = $this->service()->changeAcademicStatus(
            $profile, StudentAcademicStatus::SUSPENDED, StatusChangeSource::MANUAL,
            reason: 'Disciplinary review', effectiveFrom: $effective, actor: $actor, context: null,
        );

        // Current authoritative status changed.
        $this->assertSame(StudentAcademicStatus::SUSPENDED, $updated->academic_status);
        $this->assertSame(StudentAcademicStatus::SUSPENDED, $profile->fresh()->academic_status);

        $histories = StudentStatusHistory::where('student_profile_id', $profile->id)->orderBy('id')->get();
        $this->assertCount(2, $histories);

        // Initial interval now closed at the transition time.
        $this->assertNotNull($histories[0]->effective_to);
        $this->assertTrue($histories[0]->effective_to->equalTo($effective));

        // New interval: old = ACTIVE, new = SUSPENDED, open, correct effective_from and actor.
        $new = $histories[1];
        $this->assertSame(StudentAcademicStatus::ACTIVE, $new->old_status);
        $this->assertSame(StudentAcademicStatus::SUSPENDED, $new->new_status);
        $this->assertNull($new->effective_to);
        $this->assertTrue($new->effective_from->equalTo($effective));
        $this->assertSame($actor->id, $new->changed_by_user_id);
        $this->assertSame(StatusChangeSource::MANUAL, $new->source);

        // Exactly one open interval remains.
        $this->assertSame(1, StudentStatusHistory::where('student_profile_id', $profile->id)->whereNull('effective_to')->count());
    }

    public function test_status_change_writes_safe_audit(): void
    {
        $dept = Department::factory()->create();
        $actor = $this->actor();
        $profile = $this->service()->createStudent($this->payload($dept), $actor, null);

        $this->service()->changeAcademicStatus($profile, StudentAcademicStatus::LEAVE, StatusChangeSource::MANUAL, reason: 'Medical', actor: $actor, context: null);

        $log = AuditLog::where('action', 'STUDENT_STATUS_CHANGED')->where('entity_id', $profile->uuid)->first();
        $this->assertNotNull($log);
        $this->assertSame('student', $log->module);
        $this->assertSame('ACTIVE', $log->old_values['academic_status']);
        $this->assertSame('LEAVE', $log->new_values['academic_status']);
        $this->assertSame('Medical', $log->new_values['reason']);
        $this->assertArrayNotHasKey('id', $log->new_values);
        $this->assertArrayNotHasKey('student_profile_id', $log->new_values);
    }

    public function test_changed_by_comes_from_actor_only(): void
    {
        $dept = Department::factory()->create();
        $actor = $this->actor();
        $profile = $this->service()->createStudent($this->payload($dept), null, null);

        $updated = $this->service()->changeAcademicStatus($profile, StudentAcademicStatus::GRADUATED, StatusChangeSource::MANUAL, actor: $actor);
        $this->assertSame($actor->id, $updated->statusHistories()->whereNotNull('old_status')->sole()->changed_by_user_id);
    }

    public function test_no_op_transition_rejected(): void
    {
        $dept = Department::factory()->create();
        $profile = $this->service()->createStudent($this->payload($dept, ['academic_status' => 'ACTIVE']), null, null);

        $this->expectExceptionMessage('already in the requested academic status');
        $this->service()->changeAcademicStatus($profile, StudentAcademicStatus::ACTIVE, StatusChangeSource::MANUAL);
    }

    public function test_reserved_sources_rejected(): void
    {
        $dept = Department::factory()->create();
        $profile = $this->service()->createStudent($this->payload($dept), null, null);

        $this->expectException(ApiException::class);
        $this->service()->changeAcademicStatus($profile, StudentAcademicStatus::LEAVE, StatusChangeSource::IMPORT);
    }

    public function test_multiple_transitions_keep_single_open_interval(): void
    {
        $dept = Department::factory()->create();
        $profile = $this->service()->createStudent($this->payload($dept), null, null);

        $this->service()->changeAcademicStatus($profile, StudentAcademicStatus::LEAVE, StatusChangeSource::MANUAL);
        $this->service()->changeAcademicStatus($profile->fresh(), StudentAcademicStatus::ACTIVE, StatusChangeSource::MANUAL);
        $this->service()->changeAcademicStatus($profile->fresh(), StudentAcademicStatus::WITHDRAWN, StatusChangeSource::MANUAL);

        $all = StudentStatusHistory::where('student_profile_id', $profile->id)->get();
        $this->assertCount(4, $all); // initial + 3
        $this->assertSame(1, $all->whereNull('effective_to')->count());
        $this->assertSame(StudentAcademicStatus::WITHDRAWN, $profile->fresh()->academic_status);
    }

    // ================= Separation invariants =================

    public function test_status_change_does_not_touch_person_or_user_lifecycle(): void
    {
        $dept = Department::factory()->create();
        $actor = $this->actor();
        $actorStatusBefore = $actor->status;
        $profile = $this->service()->createStudent($this->payload($dept), $actor, null);
        $person = $profile->person;

        foreach ([StudentAcademicStatus::GRADUATED, StudentAcademicStatus::WITHDRAWN, StudentAcademicStatus::EXPELLED] as $status) {
            $fresh = $this->service()->createStudent($this->payload($dept), $actor, null);
            $this->service()->changeAcademicStatus($fresh, $status, StatusChangeSource::MANUAL, actor: $actor);
            // Person record lifecycle is NOT auto-archived by academic status.
            $this->assertSame(PersonStatus::ACTIVE, $fresh->person->fresh()->status);
        }

        // The subject student's person and the acting user are untouched.
        $this->assertSame(PersonStatus::ACTIVE, $person->fresh()->status);
        $this->assertSame($actorStatusBefore, $actor->fresh()->status);
        $this->assertSame(0, $actor->fresh()->roles()->count());
    }

    public function test_academic_status_set_contains_no_access_decisions(): void
    {
        $values = array_column(StudentAcademicStatus::cases(), 'value');
        foreach (['ALLOW', 'REVIEW', 'DENY'] as $forbidden) {
            $this->assertNotContains($forbidden, $values);
        }
    }
}
