<?php

namespace Tests\Feature;

use App\Enums\AcademicStatus;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * STD-005 — Academic Master Data APIs (Departments / Programs / Classrooms).
 *
 * Covers auth/permission boundaries, UUID-only public identity, master-data
 * lifecycle (ACTIVE/INACTIVE, no hard delete), cross-entity consistency,
 * pagination/filter/sort whitelisting, and audit integration.
 */
class AcademicMasterApiTest extends TestCase
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
        $role = Role::create(['code' => 'ACADEMIC_'.uniqid(), 'name' => 'Academic', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function viewer(): User
    {
        return $this->userWith(['academic.view']);
    }

    private function manager(): User
    {
        return $this->userWith(['academic.view', 'academic.manage']);
    }

    // ================= Security / routes =================

    public function test_all_endpoints_require_authentication(): void
    {
        $dept = Department::factory()->create();
        $prog = Program::factory()->create();
        $room = Classroom::factory()->create();

        $this->getJson('/api/v1/departments')->assertStatus(401);
        $this->postJson('/api/v1/departments', [])->assertStatus(401);
        $this->getJson("/api/v1/departments/{$dept->uuid}")->assertStatus(401);
        $this->putJson("/api/v1/departments/{$dept->uuid}", [])->assertStatus(401);
        $this->getJson('/api/v1/programs')->assertStatus(401);
        $this->getJson("/api/v1/programs/{$prog->uuid}")->assertStatus(401);
        $this->getJson('/api/v1/classrooms')->assertStatus(401);
        $this->getJson("/api/v1/classrooms/{$room->uuid}")->assertStatus(401);
    }

    public function test_reads_require_academic_view(): void
    {
        $nobody = $this->userWith([]);
        $this->actingAs($nobody, 'web')->getJson('/api/v1/departments')->assertStatus(403);
        $this->actingAs($nobody, 'web')->getJson('/api/v1/programs')->assertStatus(403);
        $this->actingAs($nobody, 'web')->getJson('/api/v1/classrooms')->assertStatus(403);
    }

    public function test_mutations_require_academic_manage_not_view(): void
    {
        $viewer = $this->viewer();
        // view can read
        $this->actingAs($viewer, 'web')->getJson('/api/v1/departments')->assertOk();
        // but cannot mutate
        $this->actingAs($viewer, 'web')->postJson('/api/v1/departments', [
            'code' => 'X1', 'name' => 'X',
        ])->assertStatus(403);

        $dept = Department::factory()->create();
        $this->actingAs($viewer, 'web')->putJson("/api/v1/departments/{$dept->uuid}", ['name' => 'Y'])->assertStatus(403);
    }

    public function test_no_delete_routes_exist(): void
    {
        $manager = $this->manager();
        $dept = Department::factory()->create();
        $prog = Program::factory()->create();
        $room = Classroom::factory()->create();

        $this->actingAs($manager, 'web')->deleteJson("/api/v1/departments/{$dept->uuid}")->assertStatus(405);
        $this->actingAs($manager, 'web')->deleteJson("/api/v1/programs/{$prog->uuid}")->assertStatus(405);
        $this->actingAs($manager, 'web')->deleteJson("/api/v1/classrooms/{$room->uuid}")->assertStatus(405);
    }

    // ================= Departments =================

    public function test_create_department(): void
    {
        $res = $this->actingAs($this->manager(), 'web')->postJson('/api/v1/departments', [
            'code' => 'ELEC',
            'name' => 'Electronics',
            'description' => 'Dept of Electronics',
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.code', 'ELEC');
        $res->assertJsonPath('data.status', 'ACTIVE');
        $this->assertArrayHasKey('uuid', $res->json('data'));
        $this->assertArrayNotHasKey('id', $res->json('data'));
        $this->assertDatabaseHas('departments', ['code' => 'ELEC']);
    }

    public function test_duplicate_department_code_rejected(): void
    {
        Department::factory()->create(['code' => 'DUP']);
        $this->actingAs($this->manager(), 'web')->postJson('/api/v1/departments', [
            'code' => 'DUP', 'name' => 'Again',
        ])->assertStatus(422);
    }

    public function test_department_list_defaults_and_pagination_cap(): void
    {
        Department::factory()->count(3)->create();
        $manager = $this->manager();

        $res = $this->actingAs($manager, 'web')->getJson('/api/v1/departments');
        $res->assertOk();
        $this->assertSame(20, $res->json('meta.pagination.per_page'));
        foreach ($res->json('data') as $row) {
            $this->assertArrayNotHasKey('id', $row);
            $this->assertArrayHasKey('uuid', $row);
        }

        $this->actingAs($manager, 'web')->getJson('/api/v1/departments?per_page=500')->assertStatus(422);
        $this->actingAs($manager, 'web')->getJson('/api/v1/departments?sort=id')->assertStatus(422);
        $this->actingAs($manager, 'web')->getJson('/api/v1/departments?sort=name&order=asc')->assertOk();
    }

    public function test_department_keyword_and_status_filter(): void
    {
        Department::factory()->create(['code' => 'FINDME', 'name' => 'Special', 'status' => 'ACTIVE']);
        Department::factory()->create(['code' => 'OTHER', 'status' => 'INACTIVE']);
        $manager = $this->manager();

        $this->assertGreaterThanOrEqual(1, $this->actingAs($manager, 'web')->getJson('/api/v1/departments?keyword=findme')->json('meta.pagination.total'));
        $inactive = $this->actingAs($manager, 'web')->getJson('/api/v1/departments?status=INACTIVE');
        foreach ($inactive->json('data') as $row) {
            $this->assertSame('INACTIVE', $row['status']);
        }
    }

    public function test_department_show_and_unknown_uuid(): void
    {
        $dept = Department::factory()->create();
        $manager = $this->manager();
        $res = $this->actingAs($manager, 'web')->getJson("/api/v1/departments/{$dept->uuid}");
        $res->assertOk()->assertJsonPath('data.uuid', $dept->uuid);
        $this->assertArrayNotHasKey('id', $res->json('data'));

        $this->actingAs($manager, 'web')->getJson('/api/v1/departments/00000000-0000-0000-0000-000000000000')->assertStatus(404);
    }

    public function test_update_department_and_code_is_immutable(): void
    {
        $dept = Department::factory()->create(['code' => 'KEEP', 'name' => 'Old']);
        $manager = $this->manager();

        $this->actingAs($manager, 'web')->putJson("/api/v1/departments/{$dept->uuid}", ['name' => 'New Name'])
            ->assertOk()->assertJsonPath('data.name', 'New Name');
        $this->assertDatabaseHas('departments', ['id' => $dept->id, 'name' => 'New Name', 'code' => 'KEEP']);

        // Attempting to change the code is rejected (prohibited).
        $this->actingAs($manager, 'web')->putJson("/api/v1/departments/{$dept->uuid}", ['code' => 'CHANGED'])
            ->assertStatus(422);
        $this->assertDatabaseHas('departments', ['id' => $dept->id, 'code' => 'KEEP']);
    }

    public function test_department_deactivation_preserves_record(): void
    {
        $dept = Department::factory()->create(['status' => 'ACTIVE']);
        $this->actingAs($this->manager(), 'web')->putJson("/api/v1/departments/{$dept->uuid}", ['status' => 'INACTIVE'])
            ->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        // Row still present — no hard delete.
        $this->assertDatabaseHas('departments', ['id' => $dept->id, 'status' => 'INACTIVE']);
    }

    public function test_department_rejects_client_supplied_identifiers(): void
    {
        $this->actingAs($this->manager(), 'web')->postJson('/api/v1/departments', [
            'code' => 'IDT', 'name' => 'X', 'id' => 999, 'uuid' => 'abc',
        ])->assertStatus(422);
    }

    // ================= Programs =================

    public function test_create_program_resolves_department_uuid(): void
    {
        $dept = Department::factory()->create();
        $res = $this->actingAs($this->manager(), 'web')->postJson('/api/v1/programs', [
            'department_uuid' => $dept->uuid,
            'code' => 'PRG1',
            'name' => 'Program One',
            'education_level' => 'VOC_CERT',
        ]);
        $res->assertStatus(201);
        $res->assertJsonPath('data.department.uuid', $dept->uuid);
        $this->assertArrayNotHasKey('id', $res->json('data'));
        $this->assertArrayNotHasKey('department_id', $res->json('data'));
        $this->assertDatabaseHas('programs', ['code' => 'PRG1', 'department_id' => $dept->id]);
    }

    public function test_create_program_unknown_department_uuid_rejected(): void
    {
        $this->actingAs($this->manager(), 'web')->postJson('/api/v1/programs', [
            'department_uuid' => '00000000-0000-0000-0000-000000000000',
            'code' => 'PRG', 'name' => 'X', 'education_level' => 'VOC_CERT',
        ])->assertStatus(422);
    }

    public function test_program_education_level_validated(): void
    {
        $dept = Department::factory()->create();
        $this->actingAs($this->manager(), 'web')->postJson('/api/v1/programs', [
            'department_uuid' => $dept->uuid, 'code' => 'PRG', 'name' => 'X', 'education_level' => 'BOGUS',
        ])->assertStatus(422);
    }

    public function test_duplicate_program_code_within_department_rejected(): void
    {
        $dept = Department::factory()->create();
        Program::factory()->create(['department_id' => $dept->id, 'code' => 'DUPP']);
        $this->actingAs($this->manager(), 'web')->postJson('/api/v1/programs', [
            'department_uuid' => $dept->uuid, 'code' => 'DUPP', 'name' => 'X', 'education_level' => 'VOC_CERT',
        ])->assertStatus(409);
    }

    public function test_same_program_code_allowed_in_different_department(): void
    {
        $a = Department::factory()->create();
        $b = Department::factory()->create();
        Program::factory()->create(['department_id' => $a->id, 'code' => 'SHARED']);
        $this->actingAs($this->manager(), 'web')->postJson('/api/v1/programs', [
            'department_uuid' => $b->uuid, 'code' => 'SHARED', 'name' => 'X', 'education_level' => 'VOC_CERT',
        ])->assertStatus(201);
    }

    public function test_update_program_department_and_code_immutable(): void
    {
        $dept = Department::factory()->create();
        $other = Department::factory()->create();
        $prog = Program::factory()->create(['department_id' => $dept->id, 'code' => 'PK']);
        $manager = $this->manager();

        $this->actingAs($manager, 'web')->putJson("/api/v1/programs/{$prog->uuid}", ['name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed');

        $this->actingAs($manager, 'web')->putJson("/api/v1/programs/{$prog->uuid}", ['code' => 'NEW'])->assertStatus(422);
        $this->actingAs($manager, 'web')->putJson("/api/v1/programs/{$prog->uuid}", ['department_uuid' => $other->uuid])->assertStatus(422);
        $this->assertDatabaseHas('programs', ['id' => $prog->id, 'code' => 'PK', 'department_id' => $dept->id]);
    }

    public function test_program_list_filters_by_department_uuid(): void
    {
        $a = Department::factory()->create();
        $b = Department::factory()->create();
        Program::factory()->create(['department_id' => $a->id]);
        Program::factory()->create(['department_id' => $b->id]);
        $manager = $this->manager();

        $res = $this->actingAs($manager, 'web')->getJson("/api/v1/programs?department_uuid={$a->uuid}");
        $res->assertOk();
        foreach ($res->json('data') as $row) {
            $this->assertSame($a->uuid, $row['department']['uuid']);
        }
    }

    // ================= Classrooms =================

    public function test_create_classroom_with_department_and_program(): void
    {
        $dept = Department::factory()->create();
        $prog = Program::factory()->create(['department_id' => $dept->id]);
        $res = $this->actingAs($this->manager(), 'web')->postJson('/api/v1/classrooms', [
            'department_uuid' => $dept->uuid,
            'program_uuid' => $prog->uuid,
            'code' => 'RM1',
            'name' => 'Room 1',
            'academic_year' => '2567',
            'year_level' => 1,
        ]);
        $res->assertStatus(201);
        $res->assertJsonPath('data.department.uuid', $dept->uuid);
        $res->assertJsonPath('data.program.uuid', $prog->uuid);
        $this->assertArrayNotHasKey('department_id', $res->json('data'));
        $this->assertArrayNotHasKey('program_id', $res->json('data'));
    }

    public function test_create_classroom_without_program_is_allowed(): void
    {
        $dept = Department::factory()->create();
        $res = $this->actingAs($this->manager(), 'web')->postJson('/api/v1/classrooms', [
            'department_uuid' => $dept->uuid,
            'code' => 'RM2', 'name' => 'Room 2', 'academic_year' => '2567', 'year_level' => 2,
        ]);
        $res->assertStatus(201);
        $res->assertJsonPath('data.program', null);
    }

    public function test_create_classroom_rejects_program_from_other_department(): void
    {
        $dept = Department::factory()->create();
        $otherDept = Department::factory()->create();
        $foreignProg = Program::factory()->create(['department_id' => $otherDept->id]);

        $res = $this->actingAs($this->manager(), 'web')->postJson('/api/v1/classrooms', [
            'department_uuid' => $dept->uuid,
            'program_uuid' => $foreignProg->uuid,
            'code' => 'RM3', 'name' => 'Room 3', 'academic_year' => '2567', 'year_level' => 1,
        ]);
        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'ACADEMIC_RELATIONSHIP_MISMATCH');
        $this->assertDatabaseMissing('classrooms', ['code' => 'RM3']);
    }

    public function test_duplicate_classroom_code_within_department_and_year_rejected(): void
    {
        $dept = Department::factory()->create();
        Classroom::factory()->create(['department_id' => $dept->id, 'academic_year' => '2567', 'code' => 'DUPC']);
        $this->actingAs($this->manager(), 'web')->postJson('/api/v1/classrooms', [
            'department_uuid' => $dept->uuid, 'code' => 'DUPC', 'name' => 'X', 'academic_year' => '2567', 'year_level' => 1,
        ])->assertStatus(409);
    }

    public function test_same_classroom_code_allowed_in_different_year(): void
    {
        $dept = Department::factory()->create();
        Classroom::factory()->create(['department_id' => $dept->id, 'academic_year' => '2566', 'code' => 'YR']);
        $this->actingAs($this->manager(), 'web')->postJson('/api/v1/classrooms', [
            'department_uuid' => $dept->uuid, 'code' => 'YR', 'name' => 'X', 'academic_year' => '2567', 'year_level' => 1,
        ])->assertStatus(201);
    }

    public function test_update_classroom_mutable_and_immutable_fields(): void
    {
        $dept = Department::factory()->create();
        $room = Classroom::factory()->create(['department_id' => $dept->id, 'code' => 'CK', 'academic_year' => '2567']);
        $manager = $this->manager();

        $this->actingAs($manager, 'web')->putJson("/api/v1/classrooms/{$room->uuid}", ['name' => 'Renamed', 'year_level' => 3, 'status' => 'INACTIVE'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.year_level', 3);

        $this->actingAs($manager, 'web')->putJson("/api/v1/classrooms/{$room->uuid}", ['code' => 'NEW'])->assertStatus(422);
        $this->actingAs($manager, 'web')->putJson("/api/v1/classrooms/{$room->uuid}", ['academic_year' => '2999'])->assertStatus(422);
        $this->actingAs($manager, 'web')->putJson("/api/v1/classrooms/{$room->uuid}", ['department_uuid' => Department::factory()->create()->uuid])->assertStatus(422);
        $this->assertDatabaseHas('classrooms', ['id' => $room->id, 'code' => 'CK', 'academic_year' => '2567']);
    }

    public function test_update_classroom_can_change_and_clear_program(): void
    {
        $dept = Department::factory()->create();
        $prog = Program::factory()->create(['department_id' => $dept->id]);
        $room = Classroom::factory()->create(['department_id' => $dept->id, 'program_id' => null]);
        $manager = $this->manager();

        // set
        $this->actingAs($manager, 'web')->putJson("/api/v1/classrooms/{$room->uuid}", ['program_uuid' => $prog->uuid])
            ->assertOk()->assertJsonPath('data.program.uuid', $prog->uuid);
        // clear
        $this->actingAs($manager, 'web')->putJson("/api/v1/classrooms/{$room->uuid}", ['program_uuid' => null])
            ->assertOk()->assertJsonPath('data.program', null);
    }

    public function test_update_classroom_rejects_program_from_other_department(): void
    {
        $dept = Department::factory()->create();
        $room = Classroom::factory()->create(['department_id' => $dept->id]);
        $foreign = Program::factory()->create(['department_id' => Department::factory()->create()->id]);

        $this->actingAs($this->manager(), 'web')->putJson("/api/v1/classrooms/{$room->uuid}", ['program_uuid' => $foreign->uuid])
            ->assertStatus(422)->assertJsonPath('error.code', 'ACADEMIC_RELATIONSHIP_MISMATCH');
    }

    // ================= Audit integration =================

    public function test_create_and_update_write_audit_without_leaking_internal_ids(): void
    {
        $manager = $this->manager();
        $create = $this->actingAs($manager, 'web')->postJson('/api/v1/departments', ['code' => 'AUD', 'name' => 'Audited']);
        $uuid = $create->json('data.uuid');

        $log = AuditLog::where('action', 'DEPARTMENT_CREATED')->where('entity_id', $uuid)->first();
        $this->assertNotNull($log);
        $this->assertSame('academic', $log->module);
        $this->assertSame('DEPARTMENT', $log->entity_type);
        // Academic code is preserved under a non-sensitive key.
        $this->assertSame('AUD', $log->new_values['academic_code']);
        // Internal id never present, and the sanitizer stripped the generic 'code' key.
        $this->assertArrayNotHasKey('id', $log->new_values);
        $this->assertArrayNotHasKey('code', $log->new_values);

        $this->actingAs($manager, 'web')->putJson("/api/v1/departments/{$uuid}", ['name' => 'Renamed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'DEPARTMENT_UPDATED', 'entity_id' => $uuid, 'module' => 'academic']);
    }

    public function test_get_requests_do_not_write_audit(): void
    {
        $dept = Department::factory()->create();
        $manager = $this->manager();
        $before = AuditLog::count();
        $this->actingAs($manager, 'web')->getJson('/api/v1/departments')->assertOk();
        $this->actingAs($manager, 'web')->getJson("/api/v1/departments/{$dept->uuid}")->assertOk();
        $this->assertSame($before, AuditLog::count());
    }

    public function test_program_and_classroom_mutations_are_audited(): void
    {
        $dept = Department::factory()->create();
        $manager = $this->manager();

        $prog = $this->actingAs($manager, 'web')->postJson('/api/v1/programs', [
            'department_uuid' => $dept->uuid, 'code' => 'PA', 'name' => 'X', 'education_level' => 'VOC_CERT',
        ])->json('data.uuid');
        $this->assertDatabaseHas('audit_logs', ['action' => 'PROGRAM_CREATED', 'entity_id' => $prog, 'module' => 'academic']);

        $room = $this->actingAs($manager, 'web')->postJson('/api/v1/classrooms', [
            'department_uuid' => $dept->uuid, 'code' => 'CA', 'name' => 'X', 'academic_year' => '2567', 'year_level' => 1,
        ])->json('data.uuid');
        $this->assertDatabaseHas('audit_logs', ['action' => 'CLASSROOM_CREATED', 'entity_id' => $room, 'module' => 'academic']);
    }

    // ================= Regression (STD-001..004 untouched) =================

    public function test_existing_academic_schema_and_student_tables_intact(): void
    {
        $this->assertTrue(Schema::hasTable('persons'));
        $this->assertTrue(Schema::hasTable('student_profiles'));
        $this->assertTrue(Schema::hasTable('student_status_histories'));
        // Master-data status column is the ACTIVE/INACTIVE lifecycle, not student status.
        $dept = Department::factory()->create();
        $this->assertSame(AcademicStatus::ACTIVE, $dept->status);
    }
}
