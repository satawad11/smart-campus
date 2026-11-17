<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Camera;
use App\Models\Gate;
use App\Models\GateCamera;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Zone;
use App\Services\System\SystemSettingService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GATE-002 (ACR-VEH-01 Phase 5): Smart Gate configuration API: feature gate, permissions,
 * face-first activation rule, camera assignment and audit.
 */
class GateApiTest extends TestCase
{
    use RefreshDatabase;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        $this->enableVehicles();
        $this->zone = Zone::factory()->create();
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
        $role = Role::create(['code' => 'GATE_'.uniqid(), 'name' => 'Gate', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function manager(): User
    {
        return $this->userWith(['gate.view', 'gate.manage']);
    }

    private function camera(?Zone $zone = null): Camera
    {
        $zone ??= $this->zone;

        return Camera::factory()->create(['site_id' => $zone->site_id, 'zone_id' => $zone->id]);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'site_uuid' => $this->zone->site->uuid,
            'zone_uuid' => $this->zone->uuid,
            'code' => 'MAIN_IN',
            'name' => 'ประตูหน้า',
        ];
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/gates')->assertUnauthorized();
        $this->postJson('/api/v1/gates', [])->assertUnauthorized();
    }

    public function test_disabled_vehicle_module_answers_feature_disabled(): void
    {
        $this->enableVehicles(false);

        $this->actingAs($this->manager(), 'web')->getJson('/api/v1/gates')
            ->assertForbidden()->assertJsonPath('error_code', 'FEATURE_DISABLED');
    }

    public function test_viewer_can_list_and_show_but_not_change(): void
    {
        $gate = Gate::factory()->create();
        $viewer = $this->userWith(['gate.view']);

        $this->actingAs($viewer, 'web')->getJson('/api/v1/gates')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($viewer, 'web')->getJson("/api/v1/gates/{$gate->uuid}")->assertOk()
            ->assertJsonPath('data.uuid', $gate->uuid)->assertJsonMissingPath('data.id');
        $this->actingAs($viewer, 'web')->postJson('/api/v1/gates', $this->payload())->assertForbidden();
        $this->actingAs($viewer, 'web')->putJson("/api/v1/gates/{$gate->uuid}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($viewer, 'web')->putJson("/api/v1/gates/{$gate->uuid}/cameras", ['cameras' => []])->assertForbidden();
        $this->actingAs($viewer, 'web')->getJson('/api/v1/gates/form-options')->assertForbidden();
    }

    public function test_create_with_cameras_and_activate_is_audited(): void
    {
        $face = $this->camera();
        $plate = $this->camera();

        $response = $this->actingAs($this->manager(), 'web')->postJson('/api/v1/gates', $this->payload([
            'direction' => 'IN',
            'correlation_window_seconds' => 15,
            'status' => 'ACTIVE',
            'cameras' => [
                ['camera_uuid' => $face->uuid, 'role' => 'FACE'],
                ['camera_uuid' => $plate->uuid, 'role' => 'PLATE'],
            ],
        ]))->assertCreated();

        $response->assertJsonPath('data.mode', 'FACE_PRIMARY')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.direction', 'IN')
            ->assertJsonPath('data.correlation_window_seconds', 15)
            ->assertJsonCount(2, 'data.cameras');

        $log = AuditLog::where('action', 'GATE_CREATED')->firstOrFail();
        $this->assertSame('gate', $log->module);
        $this->assertCount(2, $log->new_values['cameras']);
    }

    public function test_new_gate_defaults_to_inactive(): void
    {
        $this->actingAs($this->manager(), 'web')->postJson('/api/v1/gates', $this->payload())
            ->assertCreated()->assertJsonPath('data.status', 'INACTIVE')->assertJsonPath('data.cameras', []);
    }

    public function test_active_gate_needs_a_camera_that_reads_faces(): void
    {
        $plate = $this->camera();
        $manager = $this->manager();

        $this->actingAs($manager, 'web')->postJson('/api/v1/gates', $this->payload([
            'status' => 'ACTIVE',
            'cameras' => [['camera_uuid' => $plate->uuid, 'role' => 'PLATE']],
        ]))->assertUnprocessable()->assertJsonPath('error.code', 'GATE_NEEDS_FACE_CAMERA');
        $this->assertSame(0, Gate::count(), 'a rejected create leaves nothing behind');

        $gate = Gate::factory()->create(['status' => 'INACTIVE']);
        $this->actingAs($manager, 'web')->putJson("/api/v1/gates/{$gate->uuid}", ['status' => 'ACTIVE'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'GATE_NEEDS_FACE_CAMERA');

        // Removing the last face camera from an active gate is refused too.
        $link = GateCamera::factory()->create(['role' => 'BOTH']);
        $link->gate->update(['status' => 'ACTIVE']);
        $this->actingAs($manager, 'web')->putJson("/api/v1/gates/{$link->gate->uuid}/cameras", ['cameras' => []])
            ->assertUnprocessable()->assertJsonPath('error.code', 'GATE_NEEDS_FACE_CAMERA');
        $this->assertSame(1, GateCamera::count());
    }

    public function test_mode_and_internal_ids_cannot_be_supplied(): void
    {
        $this->actingAs($this->manager(), 'web')->postJson('/api/v1/gates', $this->payload(['mode' => 'PLATE_ONLY']))
            ->assertUnprocessable()->assertJsonValidationErrors('mode', 'error.fields');
        $this->actingAs($this->manager(), 'web')->postJson('/api/v1/gates', $this->payload(['site_id' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('site_id', 'error.fields');
    }

    public function test_duplicate_code_and_foreign_zone_are_rejected(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager, 'web')->postJson('/api/v1/gates', $this->payload())->assertCreated();
        $this->actingAs($manager, 'web')->postJson('/api/v1/gates', $this->payload())
            ->assertConflict()->assertJsonPath('error.code', 'GATE_CODE_EXISTS');

        $otherZone = Zone::factory()->create();
        $this->actingAs($manager, 'web')->postJson('/api/v1/gates', $this->payload(['code' => 'X', 'zone_uuid' => $otherZone->uuid]))
            ->assertUnprocessable()->assertJsonPath('error.code', 'GATE_ZONE_SITE_MISMATCH');
    }

    public function test_camera_rules_site_one_gate_and_duplicates(): void
    {
        $manager = $this->manager();
        $gate = Gate::factory()->create(['site_id' => $this->zone->site_id, 'zone_id' => $this->zone->id]);
        $foreign = $this->camera(Zone::factory()->create());
        $used = GateCamera::factory()->create();
        $mine = $this->camera();

        $put = fn (array $cameras) => $this->actingAs($manager, 'web')->putJson("/api/v1/gates/{$gate->uuid}/cameras", ['cameras' => $cameras]);

        $put([['camera_uuid' => $foreign->uuid, 'role' => 'FACE']])
            ->assertUnprocessable()->assertJsonPath('error.code', 'GATE_CAMERA_SITE_MISMATCH');
        $put([['camera_uuid' => $mine->uuid, 'role' => 'FACE'], ['camera_uuid' => $mine->uuid, 'role' => 'PLATE']])
            ->assertUnprocessable()->assertJsonPath('error.code', 'GATE_CAMERA_DUPLICATE');

        // A camera from another gate at the same site.
        $sameSiteUsed = $this->camera();
        $other = Gate::factory()->create(['site_id' => $this->zone->site_id, 'zone_id' => $this->zone->id, 'code' => 'OTHER']);
        GateCamera::create(['gate_id' => $other->id, 'camera_id' => $sameSiteUsed->id, 'role' => 'FACE']);
        $put([['camera_uuid' => $sameSiteUsed->uuid, 'role' => 'FACE']])
            ->assertConflict()->assertJsonPath('error.code', 'GATE_CAMERA_IN_USE');

        $put([['camera_uuid' => $mine->uuid, 'role' => 'BOTH']])->assertOk()
            ->assertJsonPath('data.cameras.0.uuid', $mine->uuid)
            ->assertJsonPath('data.cameras.0.role', 'BOTH');
        $this->assertNotNull($used->refresh());
        $this->assertSame(1, AuditLog::where('action', 'GATE_CAMERAS_CHANGED')->count());

        // Same set again: no change, no audit row.
        $put([['camera_uuid' => $mine->uuid, 'role' => 'BOTH']])->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'GATE_CAMERAS_CHANGED')->count());
    }

    public function test_update_changes_settings_but_not_site_or_code(): void
    {
        $gate = Gate::factory()->create(['site_id' => $this->zone->site_id, 'zone_id' => $this->zone->id]);
        $newZone = Zone::factory()->create(['site_id' => $this->zone->site_id]);
        $manager = $this->manager();

        $this->actingAs($manager, 'web')->putJson("/api/v1/gates/{$gate->uuid}", [
            'name' => 'ประตูหลัง',
            'zone_uuid' => $newZone->uuid,
            'direction' => 'OUT',
            'correlation_window_seconds' => 20,
        ])->assertOk()
            ->assertJsonPath('data.name', 'ประตูหลัง')
            ->assertJsonPath('data.zone.uuid', $newZone->uuid)
            ->assertJsonPath('data.direction', 'OUT');

        $log = AuditLog::where('action', 'GATE_UPDATED')->firstOrFail();
        $this->assertSame('OUT', $log->new_values['direction']);

        $this->actingAs($manager, 'web')->putJson("/api/v1/gates/{$gate->uuid}", ['code' => 'NEW'])
            ->assertUnprocessable()->assertJsonValidationErrors('code', 'error.fields');
        $this->actingAs($manager, 'web')->putJson("/api/v1/gates/{$gate->uuid}", ['correlation_window_seconds' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('correlation_window_seconds', 'error.fields');
    }

    public function test_list_filters_and_form_options(): void
    {
        $gate = Gate::factory()->create(['site_id' => $this->zone->site_id, 'zone_id' => $this->zone->id, 'name' => 'Front gate']);
        Gate::factory()->create(['name' => 'Elsewhere', 'status' => 'INACTIVE']);
        $assigned = $this->camera();
        GateCamera::create(['gate_id' => $gate->id, 'camera_id' => $assigned->id, 'role' => 'FACE']);
        $manager = $this->manager();

        $this->actingAs($manager, 'web')->getJson('/api/v1/gates?keyword=front')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.camera_count', 1);
        $this->actingAs($manager, 'web')->getJson('/api/v1/gates?status=INACTIVE')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Elsewhere');
        $this->actingAs($manager, 'web')->getJson('/api/v1/gates?site_uuid='.$this->zone->site->uuid)->assertOk()
            ->assertJsonCount(1, 'data');

        $options = $this->actingAs($manager, 'web')->getJson('/api/v1/gates/form-options')->assertOk()->json('data');
        $site = collect($options)->firstWhere('uuid', $this->zone->site->uuid);
        $this->assertSame($this->zone->uuid, $site['zones'][0]['uuid']);
        $this->assertSame($gate->uuid, collect($site['cameras'])->firstWhere('uuid', $assigned->uuid)['gate_uuid']);
    }
}
