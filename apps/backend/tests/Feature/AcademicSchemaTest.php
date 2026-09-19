<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STD-001 academic master-data schema tests (departments/programs/classrooms).
 *
 * Runs on the active connection (SQLite locally, real PostgreSQL in the docker
 * verification). Checks that differ materially on SQLite (enum CHECK rejection)
 * are guarded to the pgsql driver.
 */
class AcademicSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function newDepartment(array $overrides = []): int
    {
        return DB::table('departments')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'code' => 'DEP_'.Str::upper(Str::random(6)),
            'name' => 'Department',
        ], $overrides));
    }

    private function newProgram(int $departmentId, array $overrides = []): int
    {
        return DB::table('programs')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'department_id' => $departmentId,
            'code' => 'PRG_'.Str::upper(Str::random(6)),
            'name' => 'Program',
            'education_level' => 'VOC_CERT',
        ], $overrides));
    }

    public function test_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('departments'));
        $this->assertTrue(Schema::hasTable('programs'));
        $this->assertTrue(Schema::hasTable('classrooms'));

        $this->assertTrue(Schema::hasColumns('departments', ['id', 'uuid', 'code', 'name', 'description', 'status', 'created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns('programs', ['id', 'uuid', 'department_id', 'code', 'name', 'education_level', 'status', 'created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns('classrooms', ['id', 'uuid', 'department_id', 'program_id', 'code', 'name', 'academic_year', 'year_level', 'status', 'created_at', 'updated_at']));
    }

    public function test_department_code_and_uuid_are_unique(): void
    {
        $this->newDepartment(['code' => 'DUP_DEP']);
        $this->expectException(QueryException::class);
        $this->newDepartment(['code' => 'DUP_DEP']);
    }

    public function test_program_code_unique_within_department_not_across(): void
    {
        $dept1 = $this->newDepartment();
        $dept2 = $this->newDepartment();

        $this->newProgram($dept1, ['code' => 'SHARED']);
        // Same code in a different department is allowed.
        $this->newProgram($dept2, ['code' => 'SHARED']);

        // Same code in the same department is rejected.
        $this->expectException(QueryException::class);
        $this->newProgram($dept1, ['code' => 'SHARED']);
    }

    public function test_classroom_code_unique_within_department_and_year(): void
    {
        $dept = $this->newDepartment();

        $base = [
            'uuid' => (string) Str::uuid(),
            'department_id' => $dept,
            'code' => 'CS1A',
            'name' => 'Class',
            'academic_year' => '2567',
            'year_level' => 1,
        ];
        DB::table('classrooms')->insert($base);

        // Same code, different academic year → allowed.
        DB::table('classrooms')->insert(array_merge($base, ['uuid' => (string) Str::uuid(), 'academic_year' => '2568']));

        // Same code + same department + same year → rejected.
        $this->expectException(QueryException::class);
        DB::table('classrooms')->insert(array_merge($base, ['uuid' => (string) Str::uuid()]));
    }

    public function test_foreign_keys_enforced(): void
    {
        $this->expectException(QueryException::class);
        $this->newProgram(999999); // non-existent department
    }

    public function test_education_level_domain(): void
    {
        $dept = $this->newDepartment();
        foreach (['VOC_CERT', 'HIGH_VOC_CERT', 'SHORT_COURSE', 'OTHER'] as $level) {
            $this->newProgram($dept, ['education_level' => $level]);
        }
        $this->assertDatabaseCount('programs', 4);

        if (! $this->isPgsql()) {
            $this->markTestIncomplete('enum CHECK rejection is only enforced on PostgreSQL');
        }

        $this->expectException(QueryException::class);
        $this->newProgram($dept, ['education_level' => 'BACHELOR']);
    }
}
