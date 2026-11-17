<?php

namespace Tests\Feature;

use App\Enums\PersonType;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
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

class GuardianApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'GUA_'.uniqid(), 'name' => 'Guardian Manager', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $permissions)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_guardian_directory_is_permission_gated_and_returns_only_eligible_minimal_identities(): void
    {
        $student = StudentProfile::factory()->create();
        $parent = Person::factory()->create(['person_type' => PersonType::PARENT, 'first_name' => 'Guardian']);
        Person::factory()->create(['person_type' => PersonType::STUDENT, 'first_name' => 'Student']);
        $this->actingAs($this->userWith(['guardian.view']), 'web')
            ->getJson('/api/v1/guardians?keyword=Guardian')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $parent->uuid)
            ->assertJsonMissingPath('data.0.phone');

        $this->actingAs($this->userWith(['student.view']), 'web')->getJson('/api/v1/guardians')->assertForbidden();
        $this->actingAs($this->userWith(['guardian.view']), 'web')
            ->getJson('/api/v1/students/'.$student->uuid.'/guardians')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_parent_identity_can_be_created_read_and_updated_with_audit(): void
    {
        $manager = $this->userWith(['guardian.view', 'guardian.manage']);
        $created = $this->actingAs($manager, 'web')->postJson('/api/v1/guardians', [
            'person_code' => 'PAR-10021',
            'first_name' => 'Suda',
            'last_name' => 'Sukjai',
            'display_name' => 'Mae Suda',
        ])->assertCreated()->assertJsonPath('data.person_type', 'PARENT')->assertJsonPath('data.name', 'Mae Suda');

        $guardianUuid = $created->json('data.uuid');
        $this->getJson('/api/v1/guardians/'.$guardianUuid)->assertOk()->assertJsonPath('data.person_code', 'PAR-10021');
        $this->putJson('/api/v1/guardians/'.$guardianUuid, ['first_name' => 'Suda Updated'])
            ->assertOk()->assertJsonPath('data.first_name', 'Suda Updated');
        $this->assertDatabaseHas('audit_logs', ['action' => 'GUARDIAN_CREATED', 'entity_id' => $guardianUuid]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'GUARDIAN_UPDATED', 'entity_id' => $guardianUuid]);
        $this->actingAs($this->userWith(['guardian.view']), 'web')
            ->postJson('/api/v1/guardians', ['first_name' => 'No', 'last_name' => 'Write'])->assertForbidden();

        $staffPerson = Person::factory()->create(['person_type' => PersonType::STAFF]);
        $this->getJson('/api/v1/guardians/'.$staffPerson->uuid)->assertNotFound();
    }

    public function test_can_link_and_unlink_guardian_with_audit_history(): void
    {
        $user = $this->userWith(['guardian.view', 'guardian.manage']);
        $student = StudentProfile::factory()->create();
        $guardian = Person::factory()->create(['person_type' => PersonType::PARENT]);

        $created = $this->actingAs($user, 'web')->postJson('/api/v1/students/'.$student->uuid.'/guardians', [
            'guardian_person_uuid' => $guardian->uuid,
            'relation' => 'MOTHER',
        ])->assertCreated()->assertJsonPath('data.guardian.uuid', $guardian->uuid)->assertJsonPath('data.relation', 'MOTHER');

        $linkUuid = $created->json('data.uuid');
        $this->getJson('/api/v1/students/'.$student->uuid.'/guardians')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $linkUuid);
        $this->assertDatabaseHas('audit_logs', ['action' => 'GUARDIAN_LINK_CREATED', 'entity_id' => $linkUuid]);

        $this->deleteJson('/api/v1/guardian-links/'.$linkUuid)->assertOk();
        $this->assertDatabaseMissing('guardian_links', ['uuid' => $linkUuid]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'GUARDIAN_LINK_DELETED', 'entity_id' => $linkUuid]);
    }

    public function test_link_enforces_permissions_types_uniqueness_and_validation(): void
    {
        $student = StudentProfile::factory()->create();
        $parent = Person::factory()->create(['person_type' => PersonType::PARENT]);
        $anotherStudent = StudentProfile::factory()->create();
        $manager = $this->userWith(['guardian.manage']);

        $payload = ['guardian_person_uuid' => $parent->uuid, 'relation' => 'FATHER'];
        $this->actingAs($this->userWith(['guardian.view']), 'web')
            ->postJson('/api/v1/students/'.$student->uuid.'/guardians', $payload)->assertForbidden();
        $this->actingAs($manager, 'web')->postJson('/api/v1/students/'.$student->uuid.'/guardians', [
            'guardian_person_uuid' => $anotherStudent->person->uuid, 'relation' => 'FATHER',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/students/'.$student->uuid.'/guardians', [
            'guardian_person_uuid' => 'not-a-uuid', 'relation' => 'COUSIN',
        ])->assertUnprocessable();
        $created = $this->postJson('/api/v1/students/'.$student->uuid.'/guardians', $payload)->assertCreated();
        $this->postJson('/api/v1/students/'.$student->uuid.'/guardians', $payload)->assertStatus(409);
        $this->actingAs($this->userWith(['guardian.view']), 'web')
            ->deleteJson('/api/v1/guardian-links/'.$created->json('data.uuid'))->assertForbidden();
        $this->actingAs($manager, 'web')->deleteJson('/api/v1/guardian-links/'.$created->json('data.uuid'))->assertOk();
    }
}
