<?php

namespace App\Services\Academic;

use App\Enums\AuditAction;
use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Academic master-data domain service (STD-005).
 *
 * Owns public-UUID → internal-id resolution, cross-entity consistency, lifecycle
 * mutation, transactions, and audit for departments/programs/classrooms.
 * Controllers stay thin. No Student lifecycle here (STD-006+). Academic master
 * status is operational (ACTIVE/INACTIVE) — never an access decision.
 *
 * Note: the academic `code` is audited under the `academic_code` key because the
 * AUTH-014 audit sanitizer strips a generic `code` key (reserved for OTP codes).
 */
class AcademicMasterService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    // ---- Departments ----

    /** @param array{code:string,name:string,description?:?string,status?:string} $data */
    public function createDepartment(array $data, ?User $actor, ?AuditRequestContext $context): Department
    {
        return DB::transaction(function () use ($data, $actor, $context) {
            $department = Department::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'ACTIVE',
            ]);

            $this->audit->record(
                AuditAction::DEPARTMENT_CREATED, $actor, 'DEPARTMENT', $department->uuid,
                newValues: ['academic_code' => $department->code, 'name' => $department->name, 'status' => $department->status->value],
                context: $context, module: 'academic',
            );

            return $department;
        });
    }

    /** @param array{name?:string,description?:?string,status?:string} $data */
    public function updateDepartment(Department $department, array $data, ?User $actor, ?AuditRequestContext $context): Department
    {
        return DB::transaction(function () use ($department, $data, $actor, $context) {
            [$old, $new] = $this->applyChanges($department, $data, ['name', 'description', 'status']);
            if ($new !== []) {
                $department->save();
                $this->audit->record(
                    AuditAction::DEPARTMENT_UPDATED, $actor, 'DEPARTMENT', $department->uuid,
                    oldValues: $old, newValues: $new + ['academic_code' => $department->code],
                    context: $context, module: 'academic',
                );
            }

            return $department;
        });
    }

    // ---- Programs ----

    /** @param array{department_uuid:string,code:string,name:string,education_level:string,status?:string} $data */
    public function createProgram(array $data, ?User $actor, ?AuditRequestContext $context): Program
    {
        $department = $this->resolveDepartment($data['department_uuid']);

        return DB::transaction(function () use ($data, $department, $actor, $context) {
            $this->assertProgramCodeUnique($department->id, $data['code']);

            $program = Program::create([
                'department_id' => $department->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'education_level' => $data['education_level'],
                'status' => $data['status'] ?? 'ACTIVE',
            ]);

            $this->audit->record(
                AuditAction::PROGRAM_CREATED, $actor, 'PROGRAM', $program->uuid,
                newValues: ['academic_code' => $program->code, 'name' => $program->name, 'education_level' => $program->education_level, 'status' => $program->status->value, 'department_uuid' => $department->uuid],
                context: $context, module: 'academic',
            );

            return $program;
        });
    }

    /**
     * Department and code are immutable after creation (V1) to avoid
     * cross-department inconsistency with existing classrooms.
     *
     * @param  array{name?:string,education_level?:string,status?:string}  $data
     */
    public function updateProgram(Program $program, array $data, ?User $actor, ?AuditRequestContext $context): Program
    {
        return DB::transaction(function () use ($program, $data, $actor, $context) {
            [$old, $new] = $this->applyChanges($program, $data, ['name', 'education_level', 'status']);
            if ($new !== []) {
                $program->save();
                $this->audit->record(
                    AuditAction::PROGRAM_UPDATED, $actor, 'PROGRAM', $program->uuid,
                    oldValues: $old, newValues: $new + ['academic_code' => $program->code],
                    context: $context, module: 'academic',
                );
            }

            return $program;
        });
    }

    // ---- Classrooms ----

    /** @param array{department_uuid:string,program_uuid?:?string,code:string,name:string,academic_year:string,year_level:int,status?:string} $data */
    public function createClassroom(array $data, ?User $actor, ?AuditRequestContext $context): Classroom
    {
        $department = $this->resolveDepartment($data['department_uuid']);
        $program = $this->resolveProgramForDepartment($data['program_uuid'] ?? null, $department);

        return DB::transaction(function () use ($data, $department, $program, $actor, $context) {
            $this->assertClassroomCodeUnique($department->id, $data['academic_year'], $data['code']);

            $classroom = Classroom::create([
                'department_id' => $department->id,
                'program_id' => $program?->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'academic_year' => $data['academic_year'],
                'year_level' => $data['year_level'],
                'status' => $data['status'] ?? 'ACTIVE',
            ]);

            $this->audit->record(
                AuditAction::CLASSROOM_CREATED, $actor, 'CLASSROOM', $classroom->uuid,
                newValues: ['academic_code' => $classroom->code, 'name' => $classroom->name, 'academic_year' => $classroom->academic_year, 'year_level' => $classroom->year_level, 'status' => $classroom->status->value, 'department_uuid' => $department->uuid, 'program_uuid' => $program?->uuid],
                context: $context, module: 'academic',
            );

            return $classroom;
        });
    }

    /**
     * Department, code and academic_year are immutable after creation (V1).
     * program may be changed/cleared but must belong to the classroom's department.
     *
     * @param  array{name?:string,year_level?:int,status?:string,program_uuid?:?string}  $data
     */
    public function updateClassroom(Classroom $classroom, array $data, ?User $actor, ?AuditRequestContext $context, bool $programProvided = false): Classroom
    {
        return DB::transaction(function () use ($classroom, $data, $actor, $context, $programProvided) {
            [$old, $new] = $this->applyChanges($classroom, $data, ['name', 'year_level', 'status']);

            if ($programProvided) {
                $program = $this->resolveProgramForDepartment(
                    $data['program_uuid'] ?? null,
                    $classroom->department()->firstOrFail(),
                );
                if ($classroom->program_id !== $program?->id) {
                    $old['program_uuid'] = $classroom->program?->uuid;
                    $classroom->program_id = $program?->id;
                    $new['program_uuid'] = $program?->uuid;
                }
            }

            if ($new !== []) {
                $classroom->save();
                $this->audit->record(
                    AuditAction::CLASSROOM_UPDATED, $actor, 'CLASSROOM', $classroom->uuid,
                    oldValues: $old, newValues: $new + ['academic_code' => $classroom->code],
                    context: $context, module: 'academic',
                );
            }

            return $classroom;
        });
    }

    // ---- Helpers ----

    private function resolveDepartment(string $uuid): Department
    {
        return Department::where('uuid', $uuid)->first()
            ?? throw ApiException::conflict('DEPARTMENT_NOT_FOUND', 'The specified department does not exist.');
    }

    private function resolveProgramForDepartment(?string $programUuid, Department $department): ?Program
    {
        if ($programUuid === null) {
            return null;
        }

        $program = Program::where('uuid', $programUuid)->first()
            ?? throw ApiException::conflict('PROGRAM_NOT_FOUND', 'The specified program does not exist.');

        if ($program->department_id !== $department->id) {
            throw ApiException::unprocessable('ACADEMIC_RELATIONSHIP_MISMATCH', 'The program does not belong to the specified department.');
        }

        return $program;
    }

    private function assertProgramCodeUnique(int $departmentId, string $code): void
    {
        if (Program::where('department_id', $departmentId)->where('code', $code)->exists()) {
            throw ApiException::conflict('PROGRAM_CODE_ALREADY_EXISTS', 'A program with this code already exists in the department.');
        }
    }

    private function assertClassroomCodeUnique(int $departmentId, string $academicYear, string $code): void
    {
        $exists = Classroom::where('department_id', $departmentId)
            ->where('academic_year', $academicYear)
            ->where('code', $code)
            ->exists();
        if ($exists) {
            throw ApiException::conflict('CLASSROOM_CODE_ALREADY_EXISTS', 'A classroom with this code already exists for the department and academic year.');
        }
    }

    /**
     * Apply whitelisted changed fields to a model, returning [old, new] diffs.
     *
     * @param  list<string>  $fields
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
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

        return [$old, $new];
    }
}
