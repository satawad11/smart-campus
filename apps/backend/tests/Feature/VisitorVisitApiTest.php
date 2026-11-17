<?php

namespace Tests\Feature;

use App\Enums\PersonType;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Enums\VisitStatus;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Visit;
use App\Models\VisitorProfile;
use App\Models\Zone;
use App\Services\People\VisitService;
use App\Services\System\SystemSettingService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitorVisitApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed([PermissionSeeder::class, SystemSettingSeeder::class]);
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions, ?int $siteId = null, ?int $zoneId = null): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'VIS_'.uniqid(), 'name' => 'Visitor Operator', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $permissions)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'site_id' => $siteId, 'zone_id' => $zoneId]);

        return $user;
    }

    private function enableVisitors(): void
    {
        config(['features.locked_kill_switches.visitor' => null]);
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.visitor' => true]);
    }

    /** @return array<string, mixed> */
    private function visitorPayload(array $overrides = []): array
    {
        return array_merge([
            'person_code' => 'V'.uniqid(),
            'person_type' => 'VISITOR',
            'first_name' => 'Somchai',
            'last_name' => 'Visitor',
            'display_name' => 'Somchai V.',
            'organization' => 'Example Co.',
            'phone' => '0812345678',
            'id_document_type' => 'NATIONAL_ID',
            'id_document_last4' => '4321',
        ], $overrides);
    }

    public function test_feature_gate_fails_closed_for_visitor_and_visit_apis(): void
    {
        config(['features.locked_kill_switches.visitor' => false]);
        $user = $this->userWith(['visitor.view', 'visitor.manage', 'visit.view', 'visit.manage']);
        $this->actingAs($user, 'web')->getJson('/api/v1/visitors')->assertForbidden()->assertJsonPath('error_code', 'FEATURE_DISABLED');
        $this->getJson('/api/v1/visits')->assertForbidden()->assertJsonPath('error_code', 'FEATURE_DISABLED');
        $this->postJson('/api/v1/visitors', [])->assertForbidden()->assertJsonPath('error_code', 'FEATURE_DISABLED');
    }

    public function test_visitor_crud_minimizes_document_data_and_audits_changes(): void
    {
        $this->enableVisitors();
        $manager = $this->userWith(['visitor.view', 'visitor.manage']);
        $created = $this->actingAs($manager, 'web')->postJson('/api/v1/visitors', $this->visitorPayload())
            ->assertCreated()->assertJsonPath('data.person.person_type', 'VISITOR')
            ->assertJsonPath('data.id_document_last4', '4321');
        $visitorUuid = $created->json('data.uuid');
        $personUuid = $created->json('data.person.uuid');

        $this->assertArrayNotHasKey('id', $created->json('data'));
        $this->getJson('/api/v1/visitors?keyword=Somchai')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/visitors/'.$visitorUuid)->assertOk()->assertJsonPath('data.person.uuid', $personUuid);
        $this->putJson('/api/v1/visitors/'.$visitorUuid, ['organization' => 'Updated Org', 'id_document_last4' => '9876'])
            ->assertOk()->assertJsonPath('data.organization', 'Updated Org')->assertJsonPath('data.id_document_last4', '9876');
        $this->assertDatabaseHas('audit_logs', ['action' => 'VISITOR_CREATED', 'entity_id' => $visitorUuid]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'VISITOR_UPDATED', 'entity_id' => $visitorUuid]);
        $this->assertDatabaseMissing('audit_logs', ['entity_id' => $visitorUuid, 'new_values->profile->id_document_last4' => '9876']);
    }

    public function test_visitor_validation_permissions_not_found_and_duplicate_identity(): void
    {
        $this->enableVisitors();
        $manager = $this->userWith(['visitor.view', 'visitor.manage']);
        $viewer = $this->userWith(['visitor.view']);
        $this->actingAs($viewer, 'web')->postJson('/api/v1/visitors', $this->visitorPayload())->assertForbidden();
        $this->actingAs($manager, 'web')->postJson('/api/v1/visitors', $this->visitorPayload(['id_document_last4' => '123456789']))->assertUnprocessable();
        $payload = $this->visitorPayload();
        $this->postJson('/api/v1/visitors', $payload)->assertCreated();
        $this->postJson('/api/v1/visitors', $payload)->assertUnprocessable();
        $this->getJson('/api/v1/visitors/00000000-0000-0000-0000-000000000000')->assertNotFound();
    }

    public function test_visit_lifecycle_transitions_validate_scope_and_audit(): void
    {
        $this->enableVisitors();
        $operator = $this->userWith(['visitor.view', 'visitor.manage', 'visit.view', 'visit.manage']);
        $visitor = VisitorProfile::factory()->create()->person;
        $host = Person::factory()->create(['person_type' => PersonType::TEACHER]);
        $site = Site::factory()->create();
        $zone = Zone::factory()->create(['site_id' => $site->id]);
        $payload = [
            'visitor_person_uuid' => $visitor->uuid,
            'host_person_uuid' => $host->uuid,
            'purpose' => 'ประชุมงาน',
            'site_uuid' => $site->uuid,
            'zone_uuid' => $zone->uuid,
            'valid_from' => now()->subMinutes(2)->toIso8601String(),
            'valid_until' => now()->addMinutes(10)->toIso8601String(),
        ];
        $created = $this->actingAs($operator, 'web')->postJson('/api/v1/visits', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'SCHEDULED');
        $visitUuid = $created->json('data.uuid');
        $this->getJson('/api/v1/visits?site_uuid='.$site->uuid)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/visits/'.$visitUuid)->assertOk();
        $this->putJson('/api/v1/visits/'.$visitUuid, array_merge($payload, ['purpose' => 'ประชุมงานที่แก้ไข']))
            ->assertOk()->assertJsonPath('data.purpose', 'ประชุมงานที่แก้ไข');
        $this->postJson('/api/v1/visits/'.$visitUuid.'/check-in')->assertOk()->assertJsonPath('data.status', 'CHECKED_IN');
        $this->postJson('/api/v1/visits/'.$visitUuid.'/check-in')->assertStatus(409)->assertJsonPath('error.code', 'VISIT_NOT_CHECKINABLE');
        $this->postJson('/api/v1/visits/'.$visitUuid.'/check-out')->assertOk()->assertJsonPath('data.status', 'CHECKED_OUT');
        $this->postJson('/api/v1/visits/'.$visitUuid.'/cancel')->assertStatus(409)->assertJsonPath('error.code', 'VISIT_NOT_CANCELLABLE');
        $this->assertDatabaseHas('audit_logs', ['action' => 'VISIT_CREATED', 'entity_id' => $visitUuid]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'VISIT_UPDATED', 'entity_id' => $visitUuid]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'VISIT_CHECKED_IN', 'entity_id' => $visitUuid]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'VISIT_CHECKED_OUT', 'entity_id' => $visitUuid]);
    }

    public function test_visit_validation_permissions_zone_scope_and_cancellation(): void
    {
        $this->enableVisitors();
        $site = Site::factory()->create();
        $zone = Zone::factory()->create(['site_id' => $site->id]);
        $otherSite = Site::factory()->create();
        $visitor = VisitorProfile::factory()->create()->person;
        $payload = [
            'visitor_person_uuid' => $visitor->uuid, 'purpose' => 'ส่งเอกสาร',
            'site_uuid' => $site->uuid, 'zone_uuid' => $zone->uuid,
            'valid_from' => now()->subMinute()->toIso8601String(), 'valid_until' => now()->addHour()->toIso8601String(),
        ];
        $manager = $this->userWith(['visit.view', 'visit.manage'], $site->id);
        $viewer = $this->userWith(['visit.view'], $site->id);
        $this->actingAs($viewer, 'web')->postJson('/api/v1/visits', $payload)->assertForbidden();
        $outOfScopePayload = array_merge($payload, ['site_uuid' => $otherSite->uuid, 'zone_uuid' => null]);
        $this->actingAs($manager, 'web')->postJson('/api/v1/visits', $outOfScopePayload)->assertNotFound();
        $this->postJson('/api/v1/visits', array_merge($payload, ['valid_until' => now()->subDay()->toIso8601String()]))->assertUnprocessable();
        $created = $this->postJson('/api/v1/visits', $payload)->assertCreated();
        $uuid = $created->json('data.uuid');

        $this->actingAs($this->userWith(['visit.view'], $otherSite->id), 'web')->getJson('/api/v1/visits/'.$uuid)->assertNotFound();
        $this->actingAs($manager, 'web')->postJson('/api/v1/visits/'.$uuid.'/cancel')->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->postJson('/api/v1/visits/'.$uuid.'/check-in')->assertStatus(409);
        $this->assertDatabaseHas('audit_logs', ['action' => 'VISIT_CANCELLED', 'entity_id' => $uuid]);
    }

    public function test_expiry_service_marks_only_due_scheduled_visits_and_audits(): void
    {
        $due = Visit::factory()->create(['status' => VisitStatus::SCHEDULED, 'valid_until' => now()->subMinute()]);
        $active = Visit::factory()->create(['status' => VisitStatus::SCHEDULED, 'valid_until' => now()->addHour()]);
        $checkedIn = Visit::factory()->create(['status' => VisitStatus::CHECKED_IN, 'valid_until' => now()->subMinute()]);

        $this->assertSame(1, app(VisitService::class)->expireScheduled());
        $this->assertDatabaseHas('visits', ['id' => $due->id, 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('visits', ['id' => $active->id, 'status' => 'SCHEDULED']);
        $this->assertDatabaseHas('visits', ['id' => $checkedIn->id, 'status' => 'CHECKED_IN']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'VISIT_EXPIRED', 'entity_id' => $due->uuid]);
    }

    public function test_expiry_command_does_not_mutate_visits_while_feature_is_disabled(): void
    {
        config(['features.locked_kill_switches.visitor' => false]);
        $visit = Visit::factory()->create(['status' => VisitStatus::SCHEDULED, 'valid_until' => now()->subMinute()]);

        $this->artisan('visits:expire')->assertSuccessful();
        $this->assertDatabaseHas('visits', ['id' => $visit->id, 'status' => 'SCHEDULED']);
    }
}
