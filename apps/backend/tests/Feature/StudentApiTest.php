<?php

namespace Tests\Feature;

use App\Enums\PersonStatus;
use App\Enums\RoleStatus;
use App\Enums\StudentAcademicStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Student\StudentService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * STD-007 — Student CRUD/search HTTP APIs.
 *
 * Exercises the thin controller layer: routing/permission boundaries, the safe
 * StudentResource, list/filter/pagination, create and generic update delegating
 * to StudentService. The dedicated status endpoint is STD-008 and is asserted
 * to be absent here.
 */
class StudentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
    }

    /** @param  list<string>  $codes */
    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'STU_'.uniqid(), 'name' => 'Student Admin', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function fullAdmin(): User
    {
        return $this->userWith(['student.view', 'student.create', 'student.update', 'student.status.change']);
    }

    /** @return array<string, mixed> */
    private function createPayload(Department $dept, array $overrides = []): array
    {
        return array_merge([
            'person' => ['person_code' => 'P'.uniqid(), 'first_name' => 'Nok', 'last_name' => 'Suk', 'display_name' => 'Nok S.'],
            'student_code' => 'S'.uniqid(),
            'department_uuid' => $dept->uuid,
            'education_level' => 'VOC_CERT',
            'year_level' => 1,
        ], $overrides);
    }

    private function makeStudent(?Department $dept = null): StudentProfile
    {
        $dept ??= Department::factory()->create();

        return app(StudentService::class)->createStudent($this->createPayload($dept), null, null);
    }

    // ================= Routing / security =================

    public function test_endpoints_require_authentication(): void
    {
        $student = $this->makeStudent();
        $this->getJson('/api/v1/students')->assertStatus(401);
        $this->postJson('/api/v1/students', [])->assertStatus(401);
        $this->getJson("/api/v1/students/{$student->uuid}")->assertStatus(401);
        $this->putJson("/api/v1/students/{$student->uuid}", [])->assertStatus(401);
    }

    public function test_permission_boundaries(): void
    {
        $student = $this->makeStudent();
        $dept = Department::factory()->create();

        // No student.view → list/show forbidden.
        $none = $this->userWith([]);
        $this->actingAs($none, 'web')->getJson('/api/v1/students')->assertStatus(403);
        $this->actingAs($none, 'web')->getJson("/api/v1/students/{$student->uuid}")->assertStatus(403);

        // viewer can read but not create/update.
        $viewer = $this->userWith(['student.view']);
        $this->actingAs($viewer, 'web')->getJson('/api/v1/students')->assertOk();
        $this->actingAs($viewer, 'web')->postJson('/api/v1/students', $this->createPayload($dept))->assertStatus(403);
        $this->actingAs($viewer, 'web')->putJson("/api/v1/students/{$student->uuid}", ['year_level' => 2])->assertStatus(403);

        // creator cannot update; updater cannot create.
        $this->actingAs($this->userWith(['student.create']), 'web')->putJson("/api/v1/students/{$student->uuid}", ['year_level' => 2])->assertStatus(403);
        $this->actingAs($this->userWith(['student.update']), 'web')->postJson('/api/v1/students', $this->createPayload($dept))->assertStatus(403);
    }

    public function test_uuid_binding_and_numeric_id_not_exposed(): void
    {
        $student = $this->makeStudent();
        $admin = $this->fullAdmin();

        $this->actingAs($admin, 'web')->getJson("/api/v1/students/{$student->uuid}")->assertOk();
        // Numeric internal id must not resolve a resource.
        $this->actingAs($admin, 'web')->getJson("/api/v1/students/{$student->id}")->assertStatus(404);
        $this->actingAs($admin, 'web')->getJson('/api/v1/students/00000000-0000-0000-0000-000000000000')->assertStatus(404);
    }

    public function test_no_delete_route(): void
    {
        $student = $this->makeStudent();
        $admin = $this->fullAdmin();
        // No hard delete (archive is STD-009).
        $this->actingAs($admin, 'web')->deleteJson("/api/v1/students/{$student->uuid}")->assertStatus(405);
    }

    // ================= Resource shape =================

    public function test_resource_exposes_safe_fields_only(): void
    {
        $dept = Department::factory()->create();
        $program = Program::factory()->create(['department_id' => $dept->id, 'education_level' => 'VOC_CERT']);
        $classroom = Classroom::factory()->create(['department_id' => $dept->id, 'program_id' => $program->id]);
        $student = app(StudentService::class)->createStudent($this->createPayload($dept, [
            'program_uuid' => $program->uuid, 'classroom_uuid' => $classroom->uuid, 'education_level' => 'VOC_CERT',
        ]), null, null);

        $data = $this->actingAs($this->fullAdmin(), 'web')->getJson("/api/v1/students/{$student->uuid}")->assertOk()->json('data');

        $this->assertSame($student->uuid, $data['uuid']);
        $this->assertSame('ACTIVE', $data['academic_status']);
        $this->assertSame($program->uuid, $data['program']['uuid']);
        $this->assertSame('VOC_CERT', $data['program']['education_level']);
        $this->assertSame($classroom->uuid, $data['classroom']['uuid']);
        $this->assertArrayHasKey('academic_year', $data['classroom']);

        // Safe person identity present.
        $this->assertSame($student->person->uuid, $data['person']['uuid']);
        $this->assertArrayHasKey('first_name', $data['person']);

        // Internal ids / FKs / account / metadata / access decision all absent.
        foreach (['id', 'person_id', 'department_id', 'program_id', 'classroom_id', 'changed_by_user_id', 'user_id', 'metadata', 'access_decision'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }
        foreach (['id', 'person_id', 'user_id', 'username', 'roles', 'permissions', 'password', 'sessions'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data['person']);
        }
        foreach (['id', 'department_id'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data['program']);
        }
    }

    // ================= List / filter =================

    public function test_list_pagination_defaults_and_cap(): void
    {
        $dept = Department::factory()->create();
        foreach (range(1, 3) as $_) {
            $this->makeStudent($dept);
        }
        $admin = $this->fullAdmin();

        $res = $this->actingAs($admin, 'web')->getJson('/api/v1/students');
        $res->assertOk();
        $this->assertSame(20, $res->json('meta.pagination.per_page'));
        $this->assertGreaterThanOrEqual(3, $res->json('meta.pagination.total'));
        foreach ($res->json('data') as $row) {
            $this->assertArrayNotHasKey('id', $row);
            $this->assertArrayHasKey('uuid', $row);
        }

        $this->actingAs($admin, 'web')->getJson('/api/v1/students?per_page=500')->assertStatus(422);
        $this->actingAs($admin, 'web')->getJson('/api/v1/students?sort=person_id')->assertStatus(422);
        $this->actingAs($admin, 'web')->getJson('/api/v1/students?sort=student_code&order=asc')->assertOk();
    }

    public function test_list_filters(): void
    {
        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();
        $svc = app(StudentService::class);
        $target = $svc->createStudent($this->createPayload($deptA, [
            'student_code' => 'FIND-123', 'person' => ['first_name' => 'Zaphod', 'last_name' => 'B'],
        ]), null, null);
        $svc->createStudent($this->createPayload($deptB), null, null);
        $admin = $this->fullAdmin();

        // keyword by student_code
        $this->assertGreaterThanOrEqual(1, $this->actingAs($admin, 'web')->getJson('/api/v1/students?keyword=find-123')->json('meta.pagination.total'));
        // keyword by person name
        $this->assertGreaterThanOrEqual(1, $this->actingAs($admin, 'web')->getJson('/api/v1/students?keyword=zaphod')->json('meta.pagination.total'));
        // student_code exact
        $this->assertSame(1, $this->actingAs($admin, 'web')->getJson('/api/v1/students?student_code=FIND-123')->json('meta.pagination.total'));
        // department filter
        $byDept = $this->actingAs($admin, 'web')->getJson("/api/v1/students?department_uuid={$deptA->uuid}");
        $byDept->assertOk();
        foreach ($byDept->json('data') as $row) {
            $this->assertSame($deptA->uuid, $row['department']['uuid']);
        }
        // academic_status filter
        $this->actingAs($admin, 'web')->getJson('/api/v1/students?academic_status=ACTIVE')->assertOk();
        $this->assertSame($target->uuid, $this->actingAs($admin, 'web')->getJson('/api/v1/students?student_code=FIND-123')->json('data.0.uuid'));
    }

    public function test_list_avoids_obvious_n_plus_one(): void
    {
        $dept = Department::factory()->create();
        foreach (range(1, 5) as $_) {
            $this->makeStudent($dept);
        }
        $admin = $this->fullAdmin();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin, 'web')->getJson('/api/v1/students')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Eager loading keeps this well below per-row growth (5 students).
        $this->assertLessThan(15, $count, "Query count $count suggests an N+1 problem");
    }

    // ================= Create =================

    public function test_create_returns_201_and_persists_domain(): void
    {
        $dept = Department::factory()->create();
        $admin = $this->fullAdmin();

        $res = $this->actingAs($admin, 'web')->postJson('/api/v1/students', $this->createPayload($dept, ['student_code' => 'NEW-1']));
        $res->assertStatus(201);
        $uuid = $res->json('data.uuid');
        $this->assertSame('ACTIVE', $res->json('data.academic_status'));

        $profile = StudentProfile::where('uuid', $uuid)->firstOrFail();
        $this->assertDatabaseHas('persons', ['id' => $profile->person_id, 'person_type' => 'STUDENT']);
        $this->assertSame(1, $profile->statusHistories()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'STUDENT_CREATED', 'entity_id' => $uuid, 'module' => 'student']);
    }

    public function test_create_rejects_internal_ids_and_status_and_linking(): void
    {
        $dept = Department::factory()->create();
        $admin = $this->fullAdmin();

        foreach ([
            ['department_id' => 1],
            ['person_id' => 1],
            ['person_uuid' => '00000000-0000-0000-0000-000000000000'],
            ['academic_status' => 'GRADUATED'],
            ['source' => 'IMPORT'],
            ['uuid' => 'x'],
        ] as $bad) {
            $this->actingAs($admin, 'web')->postJson('/api/v1/students', $this->createPayload($dept, $bad))->assertStatus(422);
        }
    }

    public function test_create_mismatched_relationship_returns_422(): void
    {
        $dept = Department::factory()->create();
        $foreignProgram = Program::factory()->create(['department_id' => Department::factory()->create()->id]);
        $this->actingAs($this->fullAdmin(), 'web')->postJson('/api/v1/students', $this->createPayload($dept, [
            'program_uuid' => $foreignProgram->uuid,
        ]))->assertStatus(422);
    }

    public function test_create_duplicate_student_code_conflict(): void
    {
        $dept = Department::factory()->create();
        $admin = $this->fullAdmin();
        $this->actingAs($admin, 'web')->postJson('/api/v1/students', $this->createPayload($dept, ['student_code' => 'DUP-9']))->assertStatus(201);
        $this->actingAs($admin, 'web')->postJson('/api/v1/students', $this->createPayload($dept, ['student_code' => 'DUP-9']))->assertStatus(409);
    }

    // ================= Update =================

    public function test_update_approved_fields_and_audit_once(): void
    {
        $student = $this->makeStudent();
        $admin = $this->fullAdmin();

        $res = $this->actingAs($admin, 'web')->putJson("/api/v1/students/{$student->uuid}", [
            'person' => ['first_name' => 'Renamed'],
            'year_level' => 3,
            'graduation_year' => 2570,
        ]);
        $res->assertOk();
        $this->assertSame('Renamed', $res->json('data.person.first_name'));
        $this->assertSame(3, $res->json('data.year_level'));

        $this->assertSame(1, AuditLog::where('action', 'STUDENT_UPDATED')->where('entity_id', $student->uuid)->count());
    }

    public function test_generic_update_cannot_touch_status_keys_or_person_status(): void
    {
        $student = $this->makeStudent();
        $admin = $this->fullAdmin();

        foreach ([
            ['academic_status' => 'SUSPENDED'],
            ['status_start_date' => '2026-01-01'],
            ['student_code' => 'CHANGED'],
            ['person' => ['status' => 'ARCHIVED']],
            ['department_uuid' => Department::factory()->create()->uuid],
        ] as $bad) {
            $this->actingAs($admin, 'web')->putJson("/api/v1/students/{$student->uuid}", $bad)->assertStatus(422);
        }

        // Nothing changed.
        $fresh = $student->fresh();
        $this->assertSame(StudentAcademicStatus::ACTIVE, $fresh->academic_status);
        $this->assertSame(PersonStatus::ACTIVE, $fresh->person->status);
    }

    public function test_update_does_not_modify_user_account(): void
    {
        $student = $this->makeStudent();
        $admin = $this->fullAdmin();
        $adminStatus = $admin->status;

        $this->actingAs($admin, 'web')->putJson("/api/v1/students/{$student->uuid}", ['person' => ['last_name' => 'NewLast']])->assertOk();

        $this->assertSame($adminStatus, $admin->fresh()->status);
        $this->assertSame(0, $student->person->fresh()->user()->count());
    }
}
