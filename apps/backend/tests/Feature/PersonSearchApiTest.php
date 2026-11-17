<?php

namespace Tests\Feature;

use App\Enums\PersonType;
use App\Enums\RoleStatus;
use App\Enums\StudentAcademicStatus;
use App\Enums\UserStatus;
use App\Models\FaceProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonSearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create([
            'code' => 'PSR_'.uniqid(),
            'name' => 'Person Search',
            'system_role' => false,
            'status' => RoleStatus::ACTIVE,
        ]);
        $role->permissions()->attach(Permission::whereIn('code', $permissions)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_face_enrollment_permission_can_search_students_and_staff_with_status_summaries(): void
    {
        $user = $this->userWith(['face.enroll']);
        $student = StudentProfile::factory()->create([
            'academic_status' => StudentAcademicStatus::GRADUATED,
        ]);
        $staff = StaffProfile::factory()->create();
        $staff->person->update(['person_type' => PersonType::TEACHER]);
        FaceProfile::factory()->create(['person_id' => $student->person_id]);

        $response = $this->actingAs($user, 'web')->getJson('/api/v1/persons?per_page=10');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonFragment([
                'uuid' => $student->person->uuid,
                'person_type' => 'STUDENT',
                'academic_status' => 'GRADUATED',
                'student_code' => $student->student_code,
                'face_enrolled' => true,
            ])
            ->assertJsonFragment([
                'uuid' => $staff->person->uuid,
                'person_type' => 'TEACHER',
                'employment_status' => 'ACTIVE',
                'staff_code' => $staff->staff_code,
                'face_enrolled' => false,
            ]);

        $this->assertArrayNotHasKey('phone', $response->json('data.0'));
        $this->assertArrayNotHasKey('email', $response->json('data.0'));
    }

    public function test_search_filters_by_person_type_and_registered_code(): void
    {
        $user = $this->userWith(['face.enroll']);
        $student = StudentProfile::factory()->create(['student_code' => 'STU-90817']);
        StaffProfile::factory()->create();

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/persons?keyword=STU-90817&person_type=STUDENT')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $student->person->uuid);

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/persons?person_type=VISITOR')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_search_rejects_invalid_group_and_unprivileged_users(): void
    {
        $user = $this->userWith(['face.test']);
        $this->actingAs($user, 'web')->getJson('/api/v1/persons')->assertForbidden();

        $authorized = $this->userWith(['face.enroll']);
        $this->actingAs($authorized, 'web')
            ->getJson('/api/v1/persons?person_type=ALIEN')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }
}
