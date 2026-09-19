<?php

namespace App\Services\Student;

use App\Enums\AuditAction;
use App\Enums\PersonType;
use App\Enums\StatusChangeSource;
use App\Enums\StudentAcademicStatus;
use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\Department;
use App\Models\Person;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\StudentStatusHistory;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Canonical Student domain service (STD-006).
 *
 * A Student = Person identity + StudentProfile academic identity. This service
 * owns the transactional invariants that keep those consistent:
 *
 *  - creation orchestration (Person + StudentProfile + initial status history +
 *    audit, all atomic);
 *  - public-UUID → internal-id resolution and cross-entity academic consistency;
 *  - the authoritative academic-status transition (current academic_status kept
 *    in lock-step with student_status_histories, plus a security audit record).
 *
 * Deliberately NOT here (separate bounded tasks / rules):
 *  - HTTP controllers/routes/Resources — STD-007 (CRUD APIs) / STD-008.
 *  - archive / photo / import / export — STD-009+.
 *  - a second student identity table, or making a Student a User.
 *  - any access decision (ALLOW/REVIEW/DENY) — that is Module 05 policy. Academic
 *    status is registry data only and is never inferred from a face/appearance.
 *  - automatic Person or User lifecycle changes driven by academic status.
 *
 * Audit subject strategy: STUDENT_* audit rows use the StudentProfile public
 * UUID as the subject (entity_type = 'STUDENT'), because the Student domain is
 * addressed by the profile UUID in the API contract (/students/{uuid}).
 *
 * Recorded assumptions (canonical docs define the status set + schema but not
 * every workflow rule; see PROJECT_RULES §18 minimum-reversible defaults):
 *  - Creation always creates a NEW Person (the contract create payload nests a
 *    person object and offers no person_uuid link); person_type is forced STUDENT.
 *  - Initial academic_status defaults to ACTIVE when the caller omits it (a newly
 *    enrolled student is normally active; the column default UNKNOWN is a
 *    schema-safety default, not the enrollment default). One initial history row
 *    is always written (old_status = null → new_status = initial status).
 *  - source is restricted to MANUAL/API here; IMPORT and SIS_SYNC are reserved
 *    for the import/sync flows (STD-011+) and are rejected on this manual path.
 *  - effective_from is business-time; it defaults to "now" and explicit values
 *    are accepted, but no future-dated scheduling engine is implemented.
 *  - Assigning master data requires it to be ACTIVE; historical profiles may keep
 *    referencing data that later becomes INACTIVE (never mutated retroactively).
 */
class StudentService
{
    /** Sources a caller may use on the manual/API student path. */
    private const CALLER_SOURCES = [StatusChangeSource::MANUAL, StatusChangeSource::API];

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * Create a Student: a new Person (type STUDENT) + its StudentProfile + one
     * initial status-history row + a STUDENT_CREATED audit record, atomically.
     *
     * @param  array{
     *     person: array{person_code?:?string, first_name:string, last_name:string, display_name?:?string, gender?:?string, primary_photo?:?string},
     *     student_code: string,
     *     department_uuid: string,
     *     program_uuid?: ?string,
     *     classroom_uuid?: ?string,
     *     education_level: string,
     *     year_level: int,
     *     academic_status?: ?string,
     *     status_effective_from?: ?string,
     *     admission_year?: ?int,
     *     graduation_year?: ?int,
     *     source?: ?string
     *  }  $data
     */
    public function createStudent(array $data, ?User $actor, ?AuditRequestContext $context): StudentProfile
    {
        $department = $this->resolveActiveDepartment($data['department_uuid']);
        $program = $this->resolveActiveProgramForDepartment($data['program_uuid'] ?? null, $department);
        $classroom = $this->resolveActiveClassroomFor($data['classroom_uuid'] ?? null, $department, $program);

        $this->assertEducationLevelConsistent($data['education_level'], $program);

        $status = $this->parseCallerStatus($data['academic_status'] ?? null) ?? StudentAcademicStatus::ACTIVE;
        $source = $this->parseCallerSource($data['source'] ?? null);
        $effectiveFrom = $this->parseEffectiveFrom($data['status_effective_from'] ?? null);

        return DB::transaction(function () use ($data, $department, $program, $classroom, $status, $source, $effectiveFrom, $actor, $context) {
            $this->assertStudentCodeAvailable($data['student_code']);

            $person = Person::create([
                'person_code' => $data['person']['person_code'] ?? null,
                'person_type' => PersonType::STUDENT,
                'first_name' => $data['person']['first_name'],
                'last_name' => $data['person']['last_name'],
                'display_name' => $data['person']['display_name'] ?? null,
                'gender' => $data['person']['gender'] ?? null,
                'primary_photo' => $data['person']['primary_photo'] ?? null,
                // Person RECORD lifecycle — independent of academic status.
                'status' => 'ACTIVE',
            ]);

            $profile = StudentProfile::create([
                'person_id' => $person->id,
                'student_code' => $data['student_code'],
                'department_id' => $department->id,
                'program_id' => $program?->id,
                'classroom_id' => $classroom?->id,
                'education_level' => $data['education_level'],
                'year_level' => $data['year_level'],
                'academic_status' => $status,
                'status_start_date' => $effectiveFrom->toDateString(),
                'status_end_date' => null,
                'admission_year' => $data['admission_year'] ?? null,
                'graduation_year' => $data['graduation_year'] ?? null,
            ]);

            // Initial history: no prior status (old = null), open interval.
            StudentStatusHistory::create([
                'student_profile_id' => $profile->id,
                'old_status' => null,
                'new_status' => $status,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'reason' => null,
                'source' => $source,
                'changed_by_user_id' => $actor?->id,
            ]);

            $this->audit->record(
                AuditAction::STUDENT_CREATED, $actor, 'STUDENT', $profile->uuid,
                newValues: [
                    'student_code' => $profile->student_code,
                    'academic_status' => $status->value,
                    'education_level' => $profile->education_level,
                    'year_level' => $profile->year_level,
                    'person_uuid' => $person->uuid,
                    'person_code' => $person->person_code,
                    'department_uuid' => $department->uuid,
                    'program_uuid' => $program?->uuid,
                    'classroom_uuid' => $classroom?->uuid,
                ],
                context: $context, module: 'student',
            );

            return $profile;
        });
    }

    /**
     * Apply an authoritative academic-status transition.
     *
     * Atomic + row-locked: the current student_profiles.academic_status is updated
     * in lock-step with the append-only history (previous open interval closed, a
     * new open interval opened) and a STUDENT_STATUS_CHANGED audit row. Any failure
     * rolls back all of it. The target status is registry data — never an access
     * decision. changed_by comes from the authenticated actor, never the client.
     */
    public function changeAcademicStatus(
        StudentProfile $profile,
        StudentAcademicStatus $newStatus,
        StatusChangeSource $source,
        ?string $reason = null,
        ?Carbon $effectiveFrom = null,
        ?User $actor = null,
        ?AuditRequestContext $context = null,
    ): StudentProfile {
        if (! in_array($source, self::CALLER_SOURCES, true)) {
            throw ApiException::unprocessable('STUDENT_STATUS_SOURCE_INVALID', 'Only MANUAL or API status changes are permitted on this path.');
        }

        $effective = $effectiveFrom !== null
            ? CarbonImmutable::instance($effectiveFrom)
            : CarbonImmutable::now();

        return DB::transaction(function () use ($profile, $newStatus, $source, $reason, $effective, $actor, $context) {
            // Serialize concurrent transitions for this student.
            /** @var StudentProfile $locked */
            $locked = StudentProfile::whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

            $oldStatus = $locked->academic_status;

            if ($oldStatus === $newStatus) {
                throw ApiException::unprocessable('STUDENT_STATUS_NO_OP', 'The student is already in the requested academic status.');
            }

            // Close the currently open history interval, if any.
            $open = StudentStatusHistory::where('student_profile_id', $locked->id)
                ->whereNull('effective_to')
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            $open?->forceFill(['effective_to' => $effective])->save();

            // Update the authoritative current status.
            $locked->academic_status = $newStatus;
            $locked->status_start_date = $effective->toDateString();
            $locked->status_end_date = null;
            $locked->save();

            // Append the new open interval.
            StudentStatusHistory::create([
                'student_profile_id' => $locked->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'effective_from' => $effective,
                'effective_to' => null,
                'reason' => $reason,
                'source' => $source,
                'changed_by_user_id' => $actor?->id,
            ]);

            $this->audit->record(
                AuditAction::STUDENT_STATUS_CHANGED, $actor, 'STUDENT', $locked->uuid,
                oldValues: ['academic_status' => $oldStatus?->value],
                newValues: [
                    'academic_status' => $newStatus->value,
                    'reason' => $reason,
                    'source' => $source->value,
                    'effective_from' => $effective->toIso8601String(),
                ],
                context: $context, module: 'student',
            );

            return $locked;
        });
    }

    /**
     * Generic Student update (STD-007). Mutates only the approved identity and
     * academic-profile fields, atomically, and writes a STUDENT_UPDATED audit
     * record when anything changed.
     *
     * Deliberately out of reach here: academic_status and the status dates
     * (dedicated status operation, STD-008), student_code / person_code /
     * person_type (immutable business keys), persons.status (separate record
     * lifecycle), any User/account field, and academic relationship
     * reassignment (department/program/classroom/education_level — reassignment
     * semantics are not defined by canonical docs, so it is deferred rather than
     * invented). None of these are accepted by the update FormRequest either.
     *
     * @param  array{
     *     person?: array{first_name?:string, last_name?:string, display_name?:?string, gender?:?string},
     *     year_level?: int,
     *     admission_year?: ?int,
     *     graduation_year?: ?int
     *  }  $data
     */
    public function updateStudent(StudentProfile $profile, array $data, ?User $actor, ?AuditRequestContext $context): StudentProfile
    {
        return DB::transaction(function () use ($profile, $data, $actor, $context) {
            $person = $profile->person()->firstOrFail();

            $personChanges = $this->applyChanges($person, $data['person'] ?? [], ['first_name', 'last_name', 'display_name', 'gender']);
            $profileChanges = $this->applyChanges($profile, $data, ['year_level', 'admission_year', 'graduation_year']);

            $changed = $personChanges !== [] || $profileChanges !== [];

            if ($personChanges !== []) {
                $person->save();
            }
            if ($profileChanges !== []) {
                $profile->save();
            }

            if ($changed) {
                $this->audit->record(
                    AuditAction::STUDENT_UPDATED, $actor, 'STUDENT', $profile->uuid,
                    oldValues: $this->prefixKeys($personChanges['old'] ?? [], 'person.') + ($profileChanges['old'] ?? []),
                    newValues: (
                        $this->prefixKeys($personChanges['new'] ?? [], 'person.') + ($profileChanges['new'] ?? [])
                    ) + ['student_code' => $profile->student_code, 'person_uuid' => $person->uuid],
                    context: $context, module: 'student',
                );
            }

            return $profile;
        });
    }

    // ---- Resolution + consistency helpers ----

    private function resolveActiveDepartment(string $uuid): Department
    {
        $department = Department::where('uuid', $uuid)->first()
            ?? throw ApiException::unprocessable('DEPARTMENT_NOT_FOUND', 'The specified department does not exist.');

        if ($department->status->value !== 'ACTIVE') {
            throw ApiException::unprocessable('DEPARTMENT_INACTIVE', 'The specified department is not active.');
        }

        return $department;
    }

    private function resolveActiveProgramForDepartment(?string $programUuid, Department $department): ?Program
    {
        if ($programUuid === null) {
            return null;
        }

        $program = Program::where('uuid', $programUuid)->first()
            ?? throw ApiException::unprocessable('PROGRAM_NOT_FOUND', 'The specified program does not exist.');

        if ($program->department_id !== $department->id) {
            throw ApiException::unprocessable('ACADEMIC_RELATIONSHIP_MISMATCH', 'The program does not belong to the specified department.');
        }

        if ($program->status->value !== 'ACTIVE') {
            throw ApiException::unprocessable('PROGRAM_INACTIVE', 'The specified program is not active.');
        }

        return $program;
    }

    private function resolveActiveClassroomFor(?string $classroomUuid, Department $department, ?Program $program): ?Classroom
    {
        if ($classroomUuid === null) {
            return null;
        }

        $classroom = Classroom::where('uuid', $classroomUuid)->first()
            ?? throw ApiException::unprocessable('CLASSROOM_NOT_FOUND', 'The specified classroom does not exist.');

        if ($classroom->department_id !== $department->id) {
            throw ApiException::unprocessable('ACADEMIC_RELATIONSHIP_MISMATCH', 'The classroom does not belong to the specified department.');
        }

        // If the classroom is itself bound to a program, it must be the student's program.
        if ($classroom->program_id !== null && $classroom->program_id !== $program?->id) {
            throw ApiException::unprocessable('ACADEMIC_RELATIONSHIP_MISMATCH', 'The classroom belongs to a different program than the student.');
        }

        if ($classroom->status->value !== 'ACTIVE') {
            throw ApiException::unprocessable('CLASSROOM_INACTIVE', 'The specified classroom is not active.');
        }

        return $classroom;
    }

    private function assertEducationLevelConsistent(string $educationLevel, ?Program $program): void
    {
        if ($program !== null && $program->education_level !== $educationLevel) {
            throw ApiException::unprocessable('EDUCATION_LEVEL_MISMATCH', 'The education level does not match the selected program.');
        }
    }

    private function assertStudentCodeAvailable(string $studentCode): void
    {
        if (StudentProfile::where('student_code', $studentCode)->exists()) {
            throw ApiException::conflict('STUDENT_CODE_ALREADY_EXISTS', 'A student with this student_code already exists.');
        }
    }

    private function parseCallerStatus(?string $status): ?StudentAcademicStatus
    {
        if ($status === null) {
            return null;
        }

        return StudentAcademicStatus::tryFrom($status)
            ?? throw ApiException::unprocessable('STUDENT_STATUS_INVALID', 'The academic status is not a canonical value.');
    }

    private function parseCallerSource(?string $source): StatusChangeSource
    {
        if ($source === null) {
            return StatusChangeSource::MANUAL;
        }

        $parsed = StatusChangeSource::tryFrom($source)
            ?? throw ApiException::unprocessable('STUDENT_STATUS_SOURCE_INVALID', 'The status change source is not recognized.');

        if (! in_array($parsed, self::CALLER_SOURCES, true)) {
            throw ApiException::unprocessable('STUDENT_STATUS_SOURCE_INVALID', 'Only MANUAL or API status changes are permitted on this path.');
        }

        return $parsed;
    }

    private function parseEffectiveFrom(?string $value): CarbonImmutable
    {
        if ($value === null) {
            return CarbonImmutable::now();
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw ApiException::unprocessable('STUDENT_STATUS_EFFECTIVE_FROM_INVALID', 'The effective_from value is not a valid timestamp.');
        }
    }

    /**
     * Apply whitelisted changed fields to a model, returning the old/new diff.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     * @return array{old?: array<string,mixed>, new?: array<string,mixed>}
     */
    private function applyChanges(object $model, array $data, array $fields): array
    {
        $old = [];
        $new = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $current = $model->{$field};
                $currentValue = $current instanceof \BackedEnum ? $current->value : $current;
                if ($currentValue !== $data[$field]) {
                    $old[$field] = $currentValue;
                    $new[$field] = $data[$field];
                    $model->{$field} = $data[$field];
                }
            }
        }

        return $new === [] ? [] : ['old' => $old, 'new' => $new];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function prefixKeys(array $values, string $prefix): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[$prefix.$key] = $value;
        }

        return $out;
    }
}
