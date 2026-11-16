<?php

namespace Tests\Feature;

use App\Enums\PersonType;
use App\Enums\RoleStatus;
use App\Enums\StaffEmploymentStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Staff\StaffService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PPL-003 (Module 09): teacher/staff API — CRUD, employment status workflow with
 * history, permissions and audit.
 */
class StaffApiTest extends TestCase
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
        $role = Role::create(['code' => 'STF_'.uniqid(), 'name' => 'Staff Admin', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function admin(): User
    {
        return $this->userWith(['staff.view', 'staff.create', 'staff.update', 'staff.status.change']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'person' => ['first_name' => 'สมชาย', 'last_name' => 'ใจดี', 'display_name' => 'ครูสมชาย'],
            'person_type' => 'TEACHER',
            'staff_code' => 'T'.uniqid(),
            'position' => 'ครูประจำแผนก',
            'phone' => '0812345678',
            'email' => 'somchai@example.test',
        ], $overrides);
    }

    private function makeStaff(): StaffProfile
    {
        return app(StaffService::class)->createStaff($this->payload(), null, null);
    }

    public function test_permissions_gate_every_endpoint(): void
    {
        $staff = $this->makeStaff();
        $none = $this->userWith([]);
        $viewer = $this->userWith(['staff.view']);

        $this->actingAs($none, 'web')->getJson('/api/v1/staff')->assertStatus(403);
        $this->actingAs($none, 'web')->getJson("/api/v1/staff/{$staff->uuid}")->assertStatus(403);

        $this->actingAs($viewer, 'web')->getJson('/api/v1/staff')->assertOk();
        $this->actingAs($viewer, 'web')->getJson("/api/v1/staff/{$staff->uuid}/status-history")->assertOk();
        $this->actingAs($viewer, 'web')->postJson('/api/v1/staff', $this->payload())->assertStatus(403);
        $this->actingAs($viewer, 'web')->putJson("/api/v1/staff/{$staff->uuid}", ['position' => 'x'])->assertStatus(403);
        $this->actingAs($viewer, 'web')->patchJson("/api/v1/staff/{$staff->uuid}/status", ['status' => 'LEAVE'])->assertStatus(403);
    }

    public function test_create_makes_person_profile_history_and_audit(): void
    {
        $admin = $this->admin();
        $dept = Department::factory()->create();

        $res = $this->actingAs($admin, 'web')->postJson('/api/v1/staff', $this->payload([
            'staff_code' => 'T0001',
            'department_uuid' => $dept->uuid,
        ]));

        $res->assertStatus(201)
            ->assertJsonPath('data.staff_code', 'T0001')
            ->assertJsonPath('data.employment_status', 'ACTIVE')
            ->assertJsonPath('data.person.person_type', 'TEACHER')
            ->assertJsonPath('data.department.uuid', $dept->uuid);
        $this->assertArrayNotHasKey('id', $res->json('data'));

        $profile = StaffProfile::where('staff_code', 'T0001')->firstOrFail();
        $this->assertSame(PersonType::TEACHER, $profile->person->person_type);
        $this->assertSame(1, $profile->statusHistories()->count());
        $this->assertTrue(AuditLog::where('action', 'STAFF_CREATED')->where('entity_id', $profile->uuid)->exists());
    }

    public function test_create_defaults_to_staff_type_and_validates(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'web')->postJson('/api/v1/staff', $this->payload(['person_type' => null]))
            ->assertStatus(201)
            ->assertJsonPath('data.person.person_type', 'STAFF');

        $this->actingAs($admin, 'web')->postJson('/api/v1/staff', $this->payload(['person_type' => 'STUDENT']))
            ->assertStatus(422);
        $this->actingAs($admin, 'web')->postJson('/api/v1/staff', $this->payload(['employment_status' => 'RETIRED']))
            ->assertStatus(422);
        $this->actingAs($admin, 'web')->postJson('/api/v1/staff', $this->payload(['email' => 'not-an-email']))
            ->assertStatus(422);
        $this->actingAs($admin, 'web')->postJson('/api/v1/staff', $this->payload(['person' => ['first_name' => 'A']]))
            ->assertStatus(422);
    }

    public function test_duplicate_staff_code_conflicts(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web')->postJson('/api/v1/staff', $this->payload(['staff_code' => 'DUP1']))->assertStatus(201);

        $this->actingAs($admin, 'web')->postJson('/api/v1/staff', $this->payload(['staff_code' => 'DUP1']))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STAFF_CODE_ALREADY_EXISTS');
    }

    public function test_index_searches_and_filters(): void
    {
        $admin = $this->admin();
        app(StaffService::class)->createStaff($this->payload(['staff_code' => 'T-A', 'person' => ['first_name' => 'Anan', 'last_name' => 'K']]), null, null);
        $b = app(StaffService::class)->createStaff($this->payload(['staff_code' => 'S-B', 'person_type' => 'STAFF', 'person' => ['first_name' => 'Boon', 'last_name' => 'M']]), null, null);
        app(StaffService::class)->changeEmploymentStatus($b, StaffEmploymentStatus::LEAVE, null, null, null);

        $this->actingAs($admin, 'web')->getJson('/api/v1/staff?keyword=anan')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.staff_code', 'T-A');
        $this->actingAs($admin, 'web')->getJson('/api/v1/staff?employment_status=LEAVE')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.staff_code', 'S-B');
        $this->actingAs($admin, 'web')->getJson('/api/v1/staff?person_type=TEACHER')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin, 'web')->getJson('/api/v1/staff?per_page=1')
            ->assertOk()->assertJsonPath('meta.pagination.total', 2);
        $this->actingAs($admin, 'web')->getJson('/api/v1/staff?department_uuid=not-a-uuid')->assertStatus(422);
    }

    public function test_update_changes_details_and_audits_only_real_changes(): void
    {
        $admin = $this->admin();
        $staff = $this->makeStaff();

        $this->actingAs($admin, 'web')->putJson("/api/v1/staff/{$staff->uuid}", [
            'person' => ['first_name' => 'สมศักดิ์'],
            'position' => 'หัวหน้าแผนก',
        ])->assertOk()
            ->assertJsonPath('data.person.first_name', 'สมศักดิ์')
            ->assertJsonPath('data.position', 'หัวหน้าแผนก');

        $audit = AuditLog::where('action', 'STAFF_UPDATED')->where('entity_id', $staff->uuid)->firstOrFail();
        $this->assertSame('หัวหน้าแผนก', $audit->new_values['position']);

        // Identity keys and status cannot be changed here.
        $this->actingAs($admin, 'web')->putJson("/api/v1/staff/{$staff->uuid}", ['staff_code' => 'X'])->assertStatus(422);
        $this->actingAs($admin, 'web')->putJson("/api/v1/staff/{$staff->uuid}", ['employment_status' => 'RETIRED'])->assertStatus(422);

        // No change -> no second audit row.
        $this->actingAs($admin, 'web')->putJson("/api/v1/staff/{$staff->uuid}", ['position' => 'หัวหน้าแผนก'])->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'STAFF_UPDATED')->where('entity_id', $staff->uuid)->count());
    }

    public function test_status_change_keeps_history_in_step_and_audits(): void
    {
        $admin = $this->admin();
        $staff = $this->makeStaff();

        $this->actingAs($admin, 'web')->patchJson("/api/v1/staff/{$staff->uuid}/status", ['status' => 'LEAVE', 'reason' => 'ลาศึกษาต่อ'])
            ->assertOk()->assertJsonPath('data.employment_status', 'LEAVE');

        $history = $this->actingAs($admin, 'web')->getJson("/api/v1/staff/{$staff->uuid}/status-history")->assertOk();
        $history->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.old_status', 'ACTIVE')
            ->assertJsonPath('data.0.new_status', 'LEAVE')
            ->assertJsonPath('data.0.reason', 'ลาศึกษาต่อ')
            ->assertJsonPath('data.0.changed_by.uuid', $admin->uuid)
            ->assertJsonPath('data.0.effective_to', null);
        $this->assertNotNull($history->json('data.1.effective_to'), 'previous interval must be closed');

        $this->assertTrue(AuditLog::where('action', 'STAFF_STATUS_CHANGED')->where('entity_id', $staff->uuid)->exists());

        $this->actingAs($admin, 'web')->patchJson("/api/v1/staff/{$staff->uuid}/status", ['status' => 'LEAVE'])
            ->assertStatus(422)->assertJsonPath('error.code', 'STAFF_STATUS_NO_OP');
        $this->actingAs($admin, 'web')->patchJson("/api/v1/staff/{$staff->uuid}/status", ['status' => 'GRADUATED'])
            ->assertStatus(422);
    }

    public function test_unknown_staff_is_not_found(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->getJson('/api/v1/staff/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }
}
