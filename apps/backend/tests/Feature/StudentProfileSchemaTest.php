<?php

namespace Tests\Feature;

use App\Enums\PersonStatus;
use App\Enums\StudentAcademicStatus;
use App\Models\Classroom;
use App\Models\Department;
use App\Models\Person;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STD-003 StudentProfile schema + model tests.
 * Active connection: SQLite locally, real PostgreSQL in docker verification.
 */
class StudentProfileSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    // 1/13: table + canonical columns.
    public function test_table_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('student_profiles'));
        $this->assertTrue(Schema::hasColumns('student_profiles', [
            'id', 'uuid', 'person_id', 'student_code', 'department_id', 'program_id',
            'classroom_id', 'education_level', 'year_level', 'academic_status',
            'status_start_date', 'status_end_date', 'admission_year', 'graduation_year',
            'metadata', 'created_at', 'updated_at',
        ]));
    }

    // 16/21/22: no soft delete, no biometric, no access-decision fields.
    public function test_no_softdelete_biometric_or_access_columns(): void
    {
        foreach (['deleted_at', 'embedding', 'face_template', 'face_image', 'liveness_score',
            'decision', 'access_decision', 'allow', 'deny', 'review'] as $col) {
            $this->assertFalse(Schema::hasColumn('student_profiles', $col), "student_profiles must not have {$col}");
        }
    }

    // 2/17: uuid unique + auto-generated + route key.
    public function test_uuid_unique_and_generated(): void
    {
        $sp = StudentProfile::factory()->create();
        $this->assertNotEmpty($sp->uuid);
        $this->assertSame('uuid', $sp->getRouteKeyName());

        $this->expectException(QueryException::class);
        StudentProfile::factory()->create(['uuid' => $sp->uuid]);
    }

    // 4: person_id is unique → one profile per person (0..1 : 1).
    public function test_person_id_is_unique(): void
    {
        $person = Person::factory()->create();
        StudentProfile::factory()->create(['person_id' => $person->id]);

        $this->expectException(QueryException::class);
        StudentProfile::factory()->create(['person_id' => $person->id]);
    }

    // 5: student_code globally unique.
    public function test_student_code_unique(): void
    {
        StudentProfile::factory()->create(['student_code' => 'S-DUP']);
        $this->expectException(QueryException::class);
        StudentProfile::factory()->create(['student_code' => 'S-DUP']);
    }

    // 3/10/11/12: FKs to person/department/program/classroom enforced.
    public function test_foreign_keys_enforced(): void
    {
        $this->expectException(QueryException::class);
        StudentProfile::factory()->create(['department_id' => 999999]);
    }

    public function test_person_fk_enforced(): void
    {
        $this->expectException(QueryException::class);
        DB::table('student_profiles')->insert([
            'uuid' => (string) Str::uuid(), 'person_id' => 999999, 'student_code' => 'X1',
            'department_id' => Department::factory()->create()->id,
            'education_level' => 'VOC_CERT', 'year_level' => 1, 'academic_status' => 'UNKNOWN',
        ]);
    }

    // 6: canonical academic statuses accepted.
    public function test_canonical_academic_statuses_accepted(): void
    {
        foreach (StudentAcademicStatus::cases() as $status) {
            $sp = StudentProfile::factory()->create(['academic_status' => $status]);
            $this->assertSame($status, $sp->fresh()->academic_status);
        }
    }

    // Default academic status is UNKNOWN (not ACTIVE).
    public function test_default_academic_status_is_unknown(): void
    {
        $person = Person::factory()->create();
        $dept = Department::factory()->create();
        $id = DB::table('student_profiles')->insertGetId([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'student_code' => 'DFLT',
            'department_id' => $dept->id, 'education_level' => 'VOC_CERT', 'year_level' => 1,
        ]);
        $this->assertSame('UNKNOWN', DB::table('student_profiles')->where('id', $id)->value('academic_status'));
    }

    // 7: invalid academic status rejected (PostgreSQL CHECK).
    public function test_invalid_academic_status_rejected(): void
    {
        if (! $this->isPgsql()) {
            $this->markTestIncomplete('academic_status CHECK rejection is only enforced on PostgreSQL');
        }

        $person = Person::factory()->create();
        $dept = Department::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('student_profiles')->insert([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'student_code' => 'BAD',
            'department_id' => $dept->id, 'education_level' => 'VOC_CERT', 'year_level' => 1,
            'academic_status' => 'NOT_A_STATUS',
        ]);
    }

    // 8/9: academic status enum is distinct from PersonStatus and has no access decisions.
    public function test_academic_status_is_not_person_status_or_access_decision(): void
    {
        $academic = array_column(StudentAcademicStatus::cases(), 'value');
        $person = array_column(PersonStatus::cases(), 'value');

        // Distinct concept: student-specific values exist; person-only values absent.
        $this->assertContains('SUSPENDED', $academic);
        $this->assertContains('EXPELLED', $academic);
        $this->assertNotContains('ARCHIVED', $academic); // PersonStatus value
        $this->assertNotContains('SUSPENDED', $person);   // not a person status

        foreach (['ALLOW', 'REVIEW', 'DENY'] as $decision) {
            $this->assertNotContains($decision, $academic, 'academic status must not contain access decisions');
        }
    }

    // 14: academic year fields are plain year integers (nullable).
    public function test_year_fields_are_nullable_integers(): void
    {
        $sp = StudentProfile::factory()->create(['admission_year' => 2567, 'graduation_year' => null]);
        $this->assertSame(2567, (int) $sp->fresh()->admission_year);
        $this->assertNull($sp->fresh()->graduation_year);
    }

    // 17/18/19: model behavior + relationships.
    public function test_relationships(): void
    {
        $dept = Department::factory()->create();
        $program = Program::factory()->create(['department_id' => $dept->id]);
        $classroom = Classroom::factory()->create(['department_id' => $dept->id, 'program_id' => $program->id]);
        $person = Person::factory()->create();

        $sp = StudentProfile::factory()->create([
            'person_id' => $person->id,
            'department_id' => $dept->id,
            'program_id' => $program->id,
            'classroom_id' => $classroom->id,
            'academic_status' => StudentAcademicStatus::ACTIVE,
        ]);

        $this->assertInstanceOf(StudentAcademicStatus::class, $sp->academic_status);
        $this->assertTrue($sp->person->is($person));
        $this->assertTrue($sp->department->is($dept));
        $this->assertTrue($sp->program->is($program));
        $this->assertTrue($sp->classroom->is($classroom));

        // 19: Person::studentProfile() hasOne.
        $this->assertTrue($person->studentProfile->is($sp));

        // metadata JSON cast round-trips.
        $sp->update(['metadata' => ['note' => 'x']]);
        $this->assertSame(['note' => 'x'], $sp->fresh()->metadata);
    }

    // nullable program/classroom allowed.
    public function test_program_and_classroom_are_optional(): void
    {
        $sp = StudentProfile::factory()->create(['program_id' => null, 'classroom_id' => null]);
        $this->assertNull($sp->program_id);
        $this->assertNull($sp->classroom_id);
    }

    // 20: no User→Student coupling introduced.
    public function test_no_user_student_coupling(): void
    {
        $this->assertFalse(method_exists(User::class, 'studentProfile'));
        $this->assertFalse(method_exists(User::class, 'student'));
        $this->assertFalse(Schema::hasColumn('student_profiles', 'user_id'));
    }
}
