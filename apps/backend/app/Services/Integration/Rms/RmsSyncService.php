<?php

namespace App\Services\Integration\Rms;

use App\Enums\AuditAction;
use App\Enums\PersonType;
use App\Enums\StatusChangeSource;
use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\Department;
use App\Models\Person;
use App\Models\Program;
use App\Models\RmsLink;
use App\Models\RmsSyncRun;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Academic\AcademicMasterService;
use App\Services\Audit\AuditService;
use App\Services\Student\StudentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * RMS API Center -> platform sync (ACR-INT-01).
 *
 *  - groups   -> Department (by major, config rms.major_departments), Program
 *                (major + level) and Classroom (short_name + year);
 *  - students -> Person STUDENT + StudentProfile, matched by student code.
 *                New students are created ACTIVE through StudentService
 *                (source SIS_SYNC). RMS owns names, department, program,
 *                classroom, level and year; academic_status is never changed;
 *  - staff    -> Person TEACHER/STAFF (by position), never a User.
 *
 * Records no longer returned by RMS are only reported (missing_since). The
 * whole run is one transaction: a dry run rolls it back, so its report is
 * exactly what a real run would do. Department/year of an existing classroom
 * are immutable here (AcademicMasterService); a group that moves gets a new
 * classroom and the old one stays for history.
 */
class RmsSyncService
{
    private const LOCK = 'rms-sync';

    private const MAX_LISTED = 200;

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, array{code: string, count: int, items: list<string>}> */
    private array $issues = [];

    /** @var array<string, Department> */
    private array $departments = [];

    /** @var array<string, Program> */
    private array $programs = [];

    private CarbonImmutable $now;

    public function __construct(
        private readonly AuditService $audit,
        private readonly AcademicMasterService $academic,
        private readonly StudentService $students,
    ) {}

    public function run(string $trigger, bool $dryRun, ?User $actor, ?RmsClient $client = null): RmsSyncRun
    {
        $run = RmsSyncRun::create([
            'status' => RmsSyncRun::RUNNING,
            'trigger' => $trigger,
            'dry_run' => $dryRun,
            'created_by_user_id' => $actor?->id,
            'started_at' => now(),
        ]);

        $lock = Cache::lock(self::LOCK, 3600);
        if (! $lock->get()) {
            return $this->fail($run, 'already_running', 'Another RMS sync is running.', $actor);
        }

        try {
            $client ??= RmsClient::fromConfig();
            try {
                $groups = $client->all('groups');
                $students = $client->all('students');
                $staff = $client->all('staff');
            } catch (RmsApiException $e) {
                return $this->fail($run, $e->error, $e->getMessage(), $actor);
            }

            $linked = RmsLink::where('resource', RmsLink::STUDENT)->whereNull('missing_since')->count();
            $minimum = (int) ceil($linked * (float) config('rms.sync.min_student_ratio', 0.5));
            if ($students === [] || count($students) < $minimum) {
                return $this->fail($run, 'too_few_students',
                    'RMS returned '.count($students)." students; {$linked} are linked here. Nothing was written.", $actor);
            }

            $this->counts = [];
            $this->issues = [];
            $this->departments = [];
            $this->programs = [];
            $this->now = CarbonImmutable::now();

            DB::beginTransaction();
            try {
                $groupMap = $this->syncGroups($groups, $actor);
                $this->syncStudents($students, $groupMap, $actor);
                $this->syncStaff($staff, $actor);
                $dryRun ? DB::rollBack() : DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            ksort($this->counts);
            $run->update([
                'status' => RmsSyncRun::COMPLETED,
                'counts' => $this->counts,
                'issues' => array_values($this->issues),
                'finished_at' => now(),
            ]);
            $this->audit->record(
                AuditAction::RMS_SYNC_COMPLETED, $actor, 'RMS_SYNC_RUN', $run->uuid,
                newValues: $this->counts + ['dry_run' => $dryRun, 'trigger' => $trigger],
                module: 'student',
            );

            return $run;
        } catch (Throwable $e) {
            report($e);

            return $this->fail($run, 'internal', class_basename($e).': '.Str::limit($e->getMessage(), 300), $actor);
        } finally {
            $lock->release();
        }
    }

    private function fail(RmsSyncRun $run, string $error, string $message, ?User $actor): RmsSyncRun
    {
        $run->update([
            'status' => RmsSyncRun::FAILED,
            'error' => Str::limit("[{$error}] {$message}", 490),
            'finished_at' => now(),
        ]);
        $this->audit->record(
            AuditAction::RMS_SYNC_FAILED, $actor, 'RMS_SYNC_RUN', $run->uuid,
            newValues: ['error' => $error, 'dry_run' => $run->dry_run, 'trigger' => $run->trigger],
            module: 'student',
        );

        return $run;
    }

    // ---- Groups -> Department / Program / Classroom ----

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, array{department: Department, program: Program, classroom: Classroom, level: string, year_level: int}>
     */
    private function syncGroups(array $groups, ?User $actor): array
    {
        $map = [];
        foreach ($groups as $group) {
            $this->count('groups_seen');
            $id = trim((string) ($group['id'] ?? ''));
            $code = trim((string) ($group['short_name'] ?? ''));
            $major = trim((string) ($group['major'] ?? ''));
            $year = trim((string) ($group['year'] ?? ''));
            $level = $this->educationLevel($group['level'] ?? null);
            $yearLevel = $this->yearLevel($group['grade'] ?? null);

            if ($id === '' || $code === '' || $major === '' || $year === '' || $level === null || $yearLevel === null) {
                $this->count('groups_skipped');
                $this->issue('GROUP_INVALID', 'group '.($code !== '' ? $code : $id).': missing id, short_name, major, year, or unknown level/grade');

                continue;
            }

            $department = $this->department($major, $actor);
            $program = $this->program($department, $major, $level, $actor);
            $name = trim((string) ($group['name'] ?? '')) ?: $code;
            $classroom = $this->classroom($id, $code, $name, $year, $yearLevel, $department, $program, $actor);
            $this->link(RmsLink::GROUP, $id, 'classroom', $classroom->id, $group);

            $map[$id] = ['department' => $department, 'program' => $program, 'classroom' => $classroom, 'level' => $level, 'year_level' => $yearLevel];
        }

        return $map;
    }

    private function educationLevel(mixed $level): ?string
    {
        $level = (string) $level;

        return match (true) {
            str_contains($level, 'ปวช') => 'VOC_CERT',
            str_contains($level, 'ปวส') => 'HIGH_VOC_CERT',
            default => null,
        };
    }

    /** RMS grade is "ปวช.1" / "ปวส.2" (or a bare number). */
    private function yearLevel(mixed $grade): ?int
    {
        if (preg_match('/(\d+)\s*$/u', trim((string) $grade), $m) !== 1) {
            return null;
        }
        $n = (int) $m[1];

        return $n >= 1 && $n <= 9 ? $n : null;
    }

    private function department(string $major, ?User $actor): Department
    {
        $mapped = config('rms.major_departments', []);
        $name = $mapped[$major] ?? 'แผนกวิชา'.$major;
        if (! isset($mapped[$major])) {
            $this->issue('MAJOR_NOT_MAPPED', "{$major} -> {$name}", unique: true);
        }

        if (! isset($this->departments[$name])) {
            $department = Department::where('name', $name)->orderBy('id')->first();
            if ($department === null) {
                $department = $this->academic->createDepartment([
                    'code' => 'RMS-'.strtoupper(substr(sha1($name), 0, 8)),
                    'name' => $name,
                    'description' => 'Created by the RMS sync',
                ], $actor, null);
                $this->count('departments_created');
                $this->issue('DEPARTMENT_CREATED', $name);
            } elseif ($department->status->value !== 'ACTIVE') {
                $this->issue('DEPARTMENT_INACTIVE', $name);
            }
            $this->departments[$name] = $department;
        }

        return $this->departments[$name];
    }

    private function program(Department $department, string $major, string $level, ?User $actor): Program
    {
        $key = "{$department->id}|{$level}|{$major}";
        if (! isset($this->programs[$key])) {
            $program = Program::where('department_id', $department->id)
                ->where('name', $major)->where('education_level', $level)->orderBy('id')->first();
            if ($program === null) {
                $program = $this->academic->createProgram([
                    'department_uuid' => $department->uuid,
                    'code' => 'RMS-'.($level === 'VOC_CERT' ? 'C' : 'H').'-'.strtoupper(substr(sha1($major), 0, 8)),
                    'name' => $major,
                    'education_level' => $level,
                ], $actor, null);
                $this->count('programs_created');
            }
            $this->programs[$key] = $program;
        }

        return $this->programs[$key];
    }

    private function classroom(string $groupId, string $code, string $name, string $year, int $yearLevel, Department $department, Program $program, ?User $actor): Classroom
    {
        $linkedId = RmsLink::where('resource', RmsLink::GROUP)->where('external_id', $groupId)->value('entity_id');
        $classroom = $linkedId !== null ? Classroom::find($linkedId) : null;

        if ($classroom !== null && ((int) $classroom->department_id !== $department->id || (string) $classroom->academic_year !== $year)) {
            $this->issue('GROUP_MOVED', "{$code} ({$year}): department or year changed in RMS; a new classroom is used, the old one is kept");
            $classroom = null;
        }

        $classroom ??= Classroom::where('department_id', $department->id)
            ->where('academic_year', $year)->where('code', $code)->first();

        if ($classroom === null) {
            $this->count('classrooms_created');

            return $this->academic->createClassroom([
                'department_uuid' => $department->uuid,
                'program_uuid' => $program->uuid,
                'code' => $code,
                'name' => $name,
                'academic_year' => $year,
                'year_level' => $yearLevel,
            ], $actor, null);
        }

        if ($classroom->status->value !== 'ACTIVE') {
            $this->issue('CLASSROOM_INACTIVE', "{$code} ({$year})");
        }
        if ($classroom->name !== $name || (int) $classroom->year_level !== $yearLevel || (int) $classroom->program_id !== $program->id) {
            $this->academic->updateClassroom($classroom, [
                'name' => $name,
                'year_level' => $yearLevel,
                'program_uuid' => $program->uuid,
            ], $actor, null, programProvided: true);
            $this->count('classrooms_updated');
        }

        return $classroom;
    }

    // ---- Students ----

    /**
     * @param  list<array<string, mixed>>  $students
     * @param  array<string, array{department: Department, program: Program, classroom: Classroom, level: string, year_level: int}>  $groupMap
     */
    private function syncStudents(array $students, array $groupMap, ?User $actor): void
    {
        $seen = [];
        foreach ($students as $student) {
            $this->count('students_seen');
            $code = trim((string) ($student['id'] ?? ''));
            $first = trim((string) ($student['name'] ?? ''));
            $last = trim((string) ($student['surname'] ?? ''));

            if ($code === '' || $first === '') {
                $this->count('students_skipped');
                $this->issue('STUDENT_INVALID', $code !== '' ? "{$code}: no name" : 'a record without a student code');

                continue;
            }
            if (isset($seen[$code])) {
                $this->count('students_skipped');
                $this->issue('STUDENT_DUPLICATE', $code);

                continue;
            }
            $seen[$code] = true;

            $context = $groupMap[(string) ($student['group_id'] ?? '')] ?? null;
            if ($context === null) {
                $this->count('students_skipped');
                $this->issue('STUDENT_NO_GROUP', $code);

                continue;
            }

            $display = trim(trim((string) ($student['prefix'] ?? '')).$first.' '.$last);

            try {
                $profile = StudentProfile::where('student_code', $code)->first();
                if ($profile === null) {
                    $profile = $this->students->createStudent([
                        'person' => ['first_name' => $first, 'last_name' => $last, 'display_name' => $display],
                        'student_code' => $code,
                        'department_uuid' => $context['department']->uuid,
                        'program_uuid' => $context['program']->uuid,
                        'classroom_uuid' => $context['classroom']->uuid,
                        'education_level' => $context['level'],
                        'year_level' => $context['year_level'],
                        'academic_status' => 'ACTIVE',
                        'source' => StatusChangeSource::SIS_SYNC->value,
                    ], $actor, null);
                    $this->count('students_created');
                } else {
                    $this->count($this->updateStudent($profile, $first, $last, $display, $context, $actor) ? 'students_updated' : 'students_unchanged');
                    if ($profile->academic_status->value !== 'ACTIVE') {
                        $this->issue('STUDENT_STATUS_REVIEW', "{$code}: {$profile->academic_status->value} here, still listed in RMS");
                    }
                }
                $this->link(RmsLink::STUDENT, $code, 'student_profile', $profile->id, $student);
            } catch (ApiException $e) {
                $this->count('students_failed');
                $this->issue('STUDENT_REJECTED', "{$code}: {$e->errorCode}");
            }
        }

        $missing = $this->markMissing(RmsLink::STUDENT, $seen);
        $this->counts['students_missing_from_rms'] = count($missing);
        foreach ($missing as $code) {
            $this->issue('STUDENT_MISSING_FROM_RMS', $code);
        }

        $notLinked = StudentProfile::where('academic_status', 'ACTIVE')
            ->whereNotIn('id', RmsLink::where('resource', RmsLink::STUDENT)->select('entity_id'))
            ->orderBy('student_code')->pluck('student_code');
        $this->counts['students_active_not_in_rms'] = $notLinked->count();
        foreach ($notLinked as $code) {
            $this->issue('STUDENT_ACTIVE_NOT_IN_RMS', (string) $code);
        }
    }

    /**
     * Apply the RMS-owned fields; academic_status is never touched.
     *
     * @param  array{department: Department, program: Program, classroom: Classroom, level: string, year_level: int}  $context
     */
    private function updateStudent(StudentProfile $profile, string $first, string $last, string $display, array $context, ?User $actor): bool
    {
        $person = $profile->person()->withTrashed()->first();
        $old = [];
        $new = [];

        if ($person !== null && $person->trashed()) {
            $this->issue('STUDENT_PERSON_DELETED', $profile->student_code);
        } elseif ($person !== null) {
            foreach (['first_name' => $first, 'last_name' => $last, 'display_name' => $display] as $field => $value) {
                if ($person->{$field} !== $value) {
                    $old["person.{$field}"] = $person->{$field};
                    $new["person.{$field}"] = $value;
                    $person->{$field} = $value;
                }
            }
        }

        $target = [
            'department_id' => [$context['department']->id, 'department_uuid', $context['department']->uuid],
            'program_id' => [$context['program']->id, 'program_uuid', $context['program']->uuid],
            'classroom_id' => [$context['classroom']->id, 'classroom_uuid', $context['classroom']->uuid],
        ];
        foreach ($target as $field => [$id, $auditKey, $uuid]) {
            if ((int) $profile->{$field} !== $id) {
                $old[$auditKey] = $profile->{$field};
                $new[$auditKey] = $uuid;
                $profile->{$field} = $id;
            }
        }
        foreach (['education_level' => $context['level'], 'year_level' => $context['year_level']] as $field => $value) {
            if ((is_int($value) ? (int) $profile->{$field} : $profile->{$field}) !== $value) {
                $old[$field] = $profile->{$field};
                $new[$field] = $value;
                $profile->{$field} = $value;
            }
        }

        if ($new === []) {
            return false;
        }

        $person?->isDirty() && $person->save();
        $profile->isDirty() && $profile->save();
        $this->audit->record(
            AuditAction::STUDENT_UPDATED, $actor, 'STUDENT', $profile->uuid,
            oldValues: $old,
            newValues: $new + ['student_code' => $profile->student_code],
            metadata: ['source' => StatusChangeSource::SIS_SYNC->value],
            module: 'student',
        );

        return true;
    }

    // ---- Staff -> Person TEACHER / STAFF ----

    /** @param  list<array<string, mixed>>  $staff */
    private function syncStaff(array $staff, ?User $actor): void
    {
        $teacherPositions = config('rms.teacher_positions', []);
        $seen = [];

        foreach ($staff as $member) {
            $this->count('staff_seen');
            $id = trim((string) ($member['id'] ?? ''));
            $fullName = trim((string) ($member['name'] ?? ''));
            $last = trim((string) ($member['surname'] ?? ''));
            if ($id === '' || $fullName === '') {
                $this->count('staff_skipped');
                $this->issue('STAFF_INVALID', 'a staff record without id or name');

                continue;
            }

            $type = in_array(trim((string) ($member['position'] ?? '')), $teacherPositions, true) ? PersonType::TEACHER : PersonType::STAFF;
            $fields = [
                'person_type' => $type->value,
                'first_name' => $this->withoutTitle($fullName),
                'last_name' => $last,
                'display_name' => trim("{$fullName} {$last}"),
            ];

            $linkedId = RmsLink::where('resource', RmsLink::STAFF)->where('external_id', $id)->value('entity_id');
            $person = $linkedId !== null ? Person::withTrashed()->find($linkedId) : null;

            if ($person === null) {
                $person = Person::create($fields + ['status' => 'ACTIVE']);
                $this->audit->record(
                    AuditAction::PERSON_CREATED, $actor, 'PERSON', $person->uuid,
                    newValues: ['person_type' => $type->value],
                    metadata: ['source' => StatusChangeSource::SIS_SYNC->value],
                    module: 'student',
                );
                $this->count('staff_created');
            } elseif ($person->trashed()) {
                $this->count('staff_skipped');
                $this->issue('STAFF_PERSON_DELETED', $person->uuid);
            } else {
                $old = [];
                $new = [];
                foreach ($fields as $field => $value) {
                    $current = $person->{$field} instanceof \BackedEnum ? $person->{$field}->value : $person->{$field};
                    if ($current !== $value) {
                        $old[$field] = $current;
                        $new[$field] = $value;
                        $person->{$field} = $value;
                    }
                }
                if ($new !== []) {
                    $person->save();
                    $this->audit->record(
                        AuditAction::PERSON_UPDATED, $actor, 'PERSON', $person->uuid,
                        oldValues: $old, newValues: $new,
                        metadata: ['source' => StatusChangeSource::SIS_SYNC->value],
                        module: 'student',
                    );
                    $this->count('staff_updated');
                } else {
                    $this->count('staff_unchanged');
                }
            }

            $seen[$id] = true;
            $this->link(RmsLink::STAFF, $id, 'person', $person->id, $member);
        }

        $this->counts['staff_missing_from_rms'] = count($this->markMissing(RmsLink::STAFF, $seen));
    }

    private function withoutTitle(string $name): string
    {
        foreach (config('rms.name_titles', []) as $title) {
            if (str_starts_with($name, $title) && trim(substr($name, strlen($title))) !== '') {
                return trim(substr($name, strlen($title)));
            }
        }

        return $name;
    }

    // ---- Links and report ----

    /** @param  array<string, mixed>  $payload */
    private function link(string $resource, string $externalId, string $entityType, int $entityId, array $payload): void
    {
        $link = RmsLink::firstOrNew(['resource' => $resource, 'external_id' => $externalId]);
        $link->fill([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload_hash' => hash('sha256', (string) json_encode($payload)),
            'last_seen_at' => $this->now,
            'missing_since' => null,
        ]);
        $link->first_seen_at ??= $this->now;
        $link->save();
    }

    /**
     * Links of $resource not seen in this run get missing_since (first time
     * only); they are never deleted.
     *
     * @param  array<string, true>  $seen  external ids seen in this run
     * @return list<string> external ids missing from RMS
     */
    private function markMissing(string $resource, array $seen): array
    {
        $missing = RmsLink::where('resource', $resource)->orderBy('external_id')->pluck('external_id')
            ->map(fn ($id) => (string) $id)
            ->reject(fn (string $id) => isset($seen[$id]))
            ->values();

        foreach ($missing->chunk(500) as $chunk) {
            RmsLink::where('resource', $resource)->whereIn('external_id', $chunk->all())
                ->whereNull('missing_since')->update(['missing_since' => $this->now]);
        }

        return $missing->all();
    }

    private function count(string $key): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
    }

    private function issue(string $code, string $item, bool $unique = false): void
    {
        $this->issues[$code] ??= ['code' => $code, 'count' => 0, 'items' => []];
        if ($unique && in_array($item, $this->issues[$code]['items'], true)) {
            return;
        }
        $this->issues[$code]['count']++;
        if (count($this->issues[$code]['items']) < self::MAX_LISTED) {
            $this->issues[$code]['items'][] = $item;
        }
    }
}
