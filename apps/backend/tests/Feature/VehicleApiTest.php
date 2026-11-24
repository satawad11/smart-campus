<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Vehicle;
use App\Models\VehicleOwnershipHistory;
use App\Services\System\SystemSettingService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VEH-004 (ACR-VEH-01): vehicle registry API — feature gate, permissions, plate rules,
 * person links and audit.
 */
class VehicleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        $this->enableVehicles();
    }

    private function enableVehicles(bool $on = true): void
    {
        config(['features.locked_kill_switches.vehicle' => null]);
        app(SystemSettingService::class)->updateGroup('FEATURE', ['feature.vehicle' => $on]);
    }

    /** @param list<string> $codes */
    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'VEH_'.uniqid(), 'name' => 'Vehicle', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function viewer(): User
    {
        return $this->userWith(['vehicle.view']);
    }

    private function manager(): User
    {
        return $this->userWith(['vehicle.view', 'vehicle.manage']);
    }

    // ---- gate and permissions ----

    public function test_endpoints_require_authentication(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->getJson('/api/v1/vehicles')->assertUnauthorized();
        $this->postJson('/api/v1/vehicles', [])->assertUnauthorized();
        $this->getJson("/api/v1/vehicles/{$vehicle->uuid}")->assertUnauthorized();
    }

    public function test_disabled_module_answers_feature_disabled_even_to_managers(): void
    {
        $this->enableVehicles(false);

        $this->actingAs($this->manager(), 'web')
            ->getJson('/api/v1/vehicles')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_DISABLED');
    }

    public function test_kill_switch_wins_over_enabled_setting(): void
    {
        config(['features.locked_kill_switches.vehicle' => false]);

        $this->actingAs($this->manager(), 'web')
            ->getJson('/api/v1/vehicles')
            ->assertStatus(403);
    }

    public function test_viewer_can_read_but_not_write(): void
    {
        $vehicle = Vehicle::factory()->create();
        $viewer = $this->viewer();

        $this->actingAs($viewer, 'web')->getJson('/api/v1/vehicles')->assertOk();
        $this->actingAs($viewer, 'web')->getJson("/api/v1/vehicles/{$vehicle->uuid}")->assertOk();
        $this->actingAs($viewer, 'web')->postJson('/api/v1/vehicles', ['plate_number' => '1กข1234', 'plate_province' => 'TH-10'])->assertForbidden();
        $this->actingAs($viewer, 'web')->putJson("/api/v1/vehicles/{$vehicle->uuid}", ['color' => 'แดง'])->assertForbidden();
        $this->actingAs($viewer, 'web')->postJson("/api/v1/vehicles/{$vehicle->uuid}/links", [])->assertForbidden();
    }

    public function test_user_without_vehicle_permission_is_forbidden(): void
    {
        $this->actingAs($this->userWith(['student.view']), 'web')->getJson('/api/v1/vehicles')->assertForbidden();
    }

    // ---- create / read / update ----

    public function test_manager_registers_vehicle_with_normalised_plate_and_province_name(): void
    {
        $manager = $this->manager();

        $res = $this->actingAs($manager, 'web')->postJson('/api/v1/vehicles', [
            'plate_number' => '๑กข-๑๒๓๔',
            'plate_province' => 'จ.เชียงใหม่',
            'vehicle_type' => 'MOTORCYCLE',
            'brand' => 'Honda',
            'color' => 'แดง',
        ])->assertCreated();

        $res->assertJsonPath('data.plate_number', '1กข1234')
            ->assertJsonPath('data.plate_display', '1กข 1234')
            ->assertJsonPath('data.plate_province', 'TH-50')
            ->assertJsonPath('data.plate_province_name', 'เชียงใหม่')
            ->assertJsonPath('data.vehicle_type', 'MOTORCYCLE')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonMissingPath('data.id');

        $vehicle = Vehicle::firstWhere('uuid', $res->json('data.uuid'));
        $this->assertSame($manager->id, $vehicle->created_by_user_id);
        $log = AuditLog::where('action', 'VEHICLE_CREATED')->firstOrFail();
        $this->assertSame($vehicle->uuid, $log->entity_id);
        $this->assertSame('VEHICLE', $log->entity_type);
    }

    public function test_invalid_plate_or_province_is_a_validation_error(): void
    {
        $this->actingAs($this->manager(), 'web')
            ->postJson('/api/v1/vehicles', ['plate_number' => 'AB 1234', 'plate_province' => 'พัทยา'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['plate_number', 'plate_province'], 'error.fields');
    }

    public function test_duplicate_live_plate_is_a_conflict(): void
    {
        Vehicle::factory()->create(['plate_number' => '1กข1234', 'plate_province' => 'TH-10']);

        $this->actingAs($this->manager(), 'web')
            ->postJson('/api/v1/vehicles', ['plate_number' => '1กข 1234', 'plate_province' => 'กทม'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VEHICLE_PLATE_EXISTS');
    }

    public function test_client_cannot_supply_internal_fields_or_change_the_plate(): void
    {
        $vehicle = Vehicle::factory()->create();
        $manager = $this->manager();

        $this->actingAs($manager, 'web')
            ->postJson('/api/v1/vehicles', ['plate_number' => '2กข1234', 'plate_province' => 'TH-10', 'id' => 5, 'uuid' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['id', 'uuid'], 'error.fields');

        $this->actingAs($manager, 'web')
            ->putJson("/api/v1/vehicles/{$vehicle->uuid}", ['plate_number' => '9ฮฮ9999'])
            ->assertStatus(422)->assertJsonValidationErrors(['plate_number'], 'error.fields');
    }

    public function test_update_changes_fields_and_audits_only_real_changes(): void
    {
        $vehicle = Vehicle::factory()->create(['color' => 'ขาว']);
        $manager = $this->manager();

        $this->actingAs($manager, 'web')
            ->putJson("/api/v1/vehicles/{$vehicle->uuid}", ['color' => 'ดำ', 'status' => 'BLOCKED'])
            ->assertOk()
            ->assertJsonPath('data.color', 'ดำ')
            ->assertJsonPath('data.status', 'BLOCKED');

        $log = AuditLog::where('action', 'VEHICLE_UPDATED')->sole();
        $this->assertSame('ขาว', $log->old_values['color']);
        $this->assertSame('BLOCKED', $log->new_values['status']);

        // No-op update writes no audit row.
        $this->actingAs($manager, 'web')->putJson("/api/v1/vehicles/{$vehicle->uuid}", ['color' => 'ดำ'])->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'VEHICLE_UPDATED')->count());
    }

    public function test_list_filters_by_normalised_plate_status_and_keyword(): void
    {
        Vehicle::factory()->create(['plate_number' => '1กข1234', 'plate_province' => 'TH-10', 'brand' => 'Toyota']);
        Vehicle::factory()->create(['plate_number' => '701234', 'plate_province' => 'TH-20', 'status' => 'BLOCKED']);
        $viewer = $this->viewer();

        $this->actingAs($viewer, 'web')->getJson('/api/v1/vehicles?plate=1กข-1234')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.plate_number', '1กข1234');
        $this->actingAs($viewer, 'web')->getJson('/api/v1/vehicles?status=BLOCKED')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.plate_display', '70-1234');
        $this->actingAs($viewer, 'web')->getJson('/api/v1/vehicles?keyword=toyota')->assertJsonCount(1, 'data');
        $this->actingAs($viewer, 'web')->getJson('/api/v1/vehicles?province=ชลบุรี')->assertJsonCount(1, 'data');
        $this->actingAs($viewer, 'web')->getJson('/api/v1/vehicles?plate=garbage')->assertJsonCount(0, 'data');
        $this->actingAs($viewer, 'web')->getJson('/api/v1/vehicles')
            ->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_unknown_vehicle_uuid_is_not_found(): void
    {
        $this->actingAs($this->viewer(), 'web')
            ->getJson('/api/v1/vehicles/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }

    // ---- person links ----

    public function test_link_and_end_person_link_keeps_history_and_audits(): void
    {
        $vehicle = Vehicle::factory()->create();
        $person = Person::factory()->create();
        $manager = $this->manager();

        $linkUuid = $this->actingAs($manager, 'web')
            ->postJson("/api/v1/vehicles/{$vehicle->uuid}/links", [
                'person_uuid' => $person->uuid,
                'relation' => 'OWNER',
                'reason' => 'ลงทะเบียนรถนักเรียน',
            ])
            ->assertCreated()
            ->assertJsonPath('data.relation', 'OWNER')
            ->assertJsonPath('data.is_current', true)
            ->assertJsonPath('data.person.uuid', $person->uuid)
            ->json('data.uuid');

        $this->actingAs($manager, 'web')->getJson("/api/v1/vehicles?person_uuid={$person->uuid}")
            ->assertJsonCount(1, 'data');

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/vehicles/{$vehicle->uuid}/links/{$linkUuid}/end", ['reason' => 'ขายรถ'])
            ->assertOk()
            ->assertJsonPath('data.is_current', false);

        $detail = $this->actingAs($manager, 'web')->getJson("/api/v1/vehicles/{$vehicle->uuid}")->assertOk();
        $detail->assertJsonCount(1, 'data.links')->assertJsonPath('data.links.0.uuid', $linkUuid);
        $this->assertNotNull($detail->json('data.links.0.effective_to'));

        $this->assertSame(1, VehicleOwnershipHistory::count());
        $this->assertSame(1, AuditLog::where('action', 'VEHICLE_PERSON_LINKED')->count());
        $unlinked = AuditLog::where('action', 'VEHICLE_PERSON_UNLINKED')->sole();
        $this->assertSame('ขายรถ', $unlinked->metadata['reason']);

        $this->actingAs($manager, 'web')->getJson("/api/v1/vehicles?person_uuid={$person->uuid}")
            ->assertJsonCount(0, 'data');
    }

    public function test_same_current_link_twice_is_a_conflict_but_other_relation_is_fine(): void
    {
        $vehicle = Vehicle::factory()->create();
        $person = Person::factory()->create();
        $manager = $this->manager();
        $url = "/api/v1/vehicles/{$vehicle->uuid}/links";

        $this->actingAs($manager, 'web')->postJson($url, ['person_uuid' => $person->uuid, 'relation' => 'DRIVER'])->assertCreated();
        $this->actingAs($manager, 'web')->postJson($url, ['person_uuid' => $person->uuid, 'relation' => 'DRIVER'])
            ->assertStatus(409)->assertJsonPath('error.code', 'VEHICLE_LINK_EXISTS');
        $this->actingAs($manager, 'web')->postJson($url, ['person_uuid' => $person->uuid, 'relation' => 'OWNER'])->assertCreated();
    }

    public function test_link_validation_and_unknown_person(): void
    {
        $vehicle = Vehicle::factory()->create();
        $manager = $this->manager();
        $url = "/api/v1/vehicles/{$vehicle->uuid}/links";

        $this->actingAs($manager, 'web')->postJson($url, ['relation' => 'BOSS'])
            ->assertStatus(422)->assertJsonValidationErrors(['person_uuid', 'relation'], 'error.fields');
        $this->actingAs($manager, 'web')->postJson($url, ['person_uuid' => '00000000-0000-0000-0000-000000000000', 'relation' => 'OWNER'])
            ->assertStatus(422)->assertJsonPath('error.code', 'PERSON_NOT_FOUND');
    }

    public function test_ending_a_link_twice_or_through_another_vehicle_fails(): void
    {
        $link = VehicleOwnershipHistory::factory()->create();
        $other = Vehicle::factory()->create();
        $manager = $this->manager();

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/vehicles/{$other->uuid}/links/{$link->uuid}/end")
            ->assertNotFound();

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/vehicles/{$link->vehicle->uuid}/links/{$link->uuid}/end", ['effective_to' => now()->subYears(2)->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('error.code', 'VEHICLE_LINK_END_BEFORE_START');

        $this->actingAs($manager, 'web')->postJson("/api/v1/vehicles/{$link->vehicle->uuid}/links/{$link->uuid}/end")->assertOk();
        $this->actingAs($manager, 'web')->postJson("/api/v1/vehicles/{$link->vehicle->uuid}/links/{$link->uuid}/end")
            ->assertStatus(409)->assertJsonPath('error.code', 'VEHICLE_LINK_ENDED');
    }

    public function test_no_delete_route_exists(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->actingAs($this->manager(), 'web')
            ->deleteJson("/api/v1/vehicles/{$vehicle->uuid}")
            ->assertStatus(405);
        $this->assertNotSoftDeleted($vehicle);
    }
}
