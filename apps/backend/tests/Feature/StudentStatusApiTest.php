<?php

namespace Tests\Feature;

use App\Enums\AcademicStatus;
use App\Enums\AuditAction;
use App\Enums\RoleStatus;
use App\Enums\StudentAcademicStatus;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STD-008 / STD-016: Student academic status change and status history API tests.
 */
class StudentStatusApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create([
            'code' => 'TEST_'.uniqid(),
            'name' => 'Test Role',
            'system_role' => false,
            'status' => RoleStatus::ACTIVE,
        ]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function createStudent(StudentAcademicStatus $status = StudentAcademicStatus::ACTIVE): StudentProfile
    {
        $dept = Department::factory()->create(['status' => AcademicStatus::ACTIVE]);
        $person = Person::factory()->create();

        return StudentProfile::factory()->create([
            'person_id' => $person->id,
            'department_id' => $dept->id,
            'academic_status' => $status,
            'status_start_date' => now()->toDateString(),
        ]);
    }

    public function test_unauthenticated_status_endpoints_return_401(): void
    {
        $student = $this->createStudent();

        $this->patchJson("/api/v1/students/{$student->uuid}/status", [
            'status' => 'LEAVE',
        ])->assertStatus(401);

        $this->getJson("/api/v1/students/{$student->uuid}/status-history")
            ->assertStatus(401);
    }

    public function test_status_change_requires_student_status_change_permission(): void
    {
        $user = $this->userWith(['student.view']); // has view but not status.change
        $student = $this->createStudent();

        $this->actingAs($user, 'web')
            ->patchJson("/api/v1/students/{$student->uuid}/status", [
                'status' => 'LEAVE',
            ])->assertStatus(403);
    }

    public function test_status_history_requires_student_view_permission(): void
    {
        $user = $this->userWith([]); // no permissions
        $student = $this->createStudent();

        $this->actingAs($user, 'web')
            ->getJson("/api/v1/students/{$student->uuid}/status-history")
            ->assertStatus(403);
    }

    public function test_status_change_succeeds_with_permission_and_records_history_and_audit(): void
    {
        $user = $this->userWith(['student.view', 'student.status.change']);
        $student = $this->createStudent(StudentAcademicStatus::ACTIVE);

        $response = $this->actingAs($user, 'web')
            ->patchJson("/api/v1/students/{$student->uuid}/status", [
                'status' => 'LEAVE',
                'reason' => 'ลาพักการเรียนเพื่อรักษาพยาบาล',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.academic_status', 'LEAVE');

        // Verify DB
        $this->assertEquals(StudentAcademicStatus::LEAVE, $student->fresh()->academic_status);

        // Verify Status History created
        $this->assertDatabaseHas('student_status_histories', [
            'student_profile_id' => $student->id,
            'old_status' => 'ACTIVE',
            'new_status' => 'LEAVE',
            'reason' => 'ลาพักการเรียนเพื่อรักษาพยาบาล',
            'changed_by_user_id' => $user->id,
        ]);

        // Verify Audit Log
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::STUDENT_STATUS_CHANGED->value,
            'entity_type' => 'STUDENT',
            'entity_id' => $student->uuid,
        ]);
    }

    public function test_no_op_status_change_is_rejected(): void
    {
        $user = $this->userWith(['student.status.change']);
        $student = $this->createStudent(StudentAcademicStatus::ACTIVE);

        $response = $this->actingAs($user, 'web')
            ->patchJson("/api/v1/students/{$student->uuid}/status", [
                'status' => 'ACTIVE',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'STUDENT_STATUS_NO_OP');
    }

    public function test_status_history_endpoint_returns_paginated_history(): void
    {
        $user = $this->userWith(['student.view', 'student.status.change']);
        $student = $this->createStudent(StudentAcademicStatus::ACTIVE);

        // Transition 1
        $this->actingAs($user, 'web')
            ->patchJson("/api/v1/students/{$student->uuid}/status", [
                'status' => 'LEAVE',
                'reason' => 'Reason 1',
            ])->assertStatus(200);

        // Transition 2
        $this->actingAs($user, 'web')
            ->patchJson("/api/v1/students/{$student->uuid}/status", [
                'status' => 'ACTIVE',
                'reason' => 'Reason 2',
            ])->assertStatus(200);

        $res = $this->actingAs($user, 'web')
            ->getJson("/api/v1/students/{$student->uuid}/status-history");

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.0.new_status', 'ACTIVE')
            ->assertJsonPath('data.0.old_status', 'LEAVE')
            ->assertJsonPath('data.0.reason', 'Reason 2')
            ->assertJsonPath('data.1.new_status', 'LEAVE')
            ->assertJsonPath('data.1.old_status', 'ACTIVE')
            ->assertJsonPath('data.1.reason', 'Reason 1');

        // Ensure internal integer IDs are not exposed
        $jsonStr = json_encode($res->json());
        $this->assertStringNotContainsString('"student_profile_id"', $jsonStr);
        $this->assertStringNotContainsString('"changed_by_user_id"', $jsonStr);
    }
}
