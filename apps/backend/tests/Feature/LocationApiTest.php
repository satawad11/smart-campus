<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Camera;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Zone;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UIX-002: sites and zones managed from the web (previously CLI only).
 */
class LocationApiTest extends TestCase
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
        $role = Role::create(['code' => 'LOC_'.uniqid(), 'name' => 'Loc', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_reading_needs_system_view_and_writing_needs_system_manage(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->userWith(['system.view']);

        $this->actingAs($this->userWith(['camera.view']), 'web')->getJson('/api/v1/sites')->assertStatus(403);
        $this->actingAs($viewer, 'web')->getJson('/api/v1/sites')->assertOk();
        $this->actingAs($viewer, 'web')->postJson('/api/v1/sites', ['code' => 'X', 'name' => 'X'])->assertStatus(403);
        $this->actingAs($viewer, 'web')->putJson("/api/v1/sites/{$site->uuid}", ['name' => 'X'])->assertStatus(403);
        $this->actingAs($viewer, 'web')->postJson("/api/v1/sites/{$site->uuid}/zones", ['code' => 'Z', 'name' => 'Z'])->assertStatus(403);
    }

    public function test_create_site_and_zones_then_list_them_with_camera_counts(): void
    {
        $admin = $this->userWith(['system.view', 'system.manage']);

        $site = $this->actingAs($admin, 'web')->postJson('/api/v1/sites', ['code' => 'MAIN', 'name' => 'อาคารหลัก'])
            ->assertStatus(201)
            ->assertJsonPath('data.code', 'MAIN')
            ->assertJsonPath('data.timezone', 'Asia/Bangkok')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->json('data');

        $this->actingAs($admin, 'web')->postJson("/api/v1/sites/{$site['uuid']}/zones", [
            'code' => 'GATE-1', 'name' => 'ประตูหน้า', 'zone_type' => 'GATE',
        ])->assertStatus(201)->assertJsonPath('data.zones.0.code', 'GATE-1');

        $zone = Zone::where('code', 'GATE-1')->firstOrFail();
        Camera::factory()->create(['site_id' => $zone->site_id, 'zone_id' => $zone->id]);

        $this->actingAs($admin, 'web')->getJson('/api/v1/sites')->assertOk()
            ->assertJsonPath('data.0.zones_count', 1)
            ->assertJsonPath('data.0.zones.0.cameras_count', 1);

        $this->assertTrue(AuditLog::where('action', 'location.site_created')->where('entity_id', $site['uuid'])->exists());
        $this->assertTrue(AuditLog::where('action', 'location.zone_created')->where('entity_id', $zone->uuid)->exists());
    }

    public function test_codes_are_unique_and_fixed(): void
    {
        $admin = $this->userWith(['system.view', 'system.manage']);
        $site = Site::factory()->create(['code' => 'MAIN']);
        $other = Site::factory()->create(['code' => 'ANNEX']);
        $zone = Zone::factory()->create(['site_id' => $site->id, 'code' => 'GATE-1']);

        $this->actingAs($admin, 'web')->postJson('/api/v1/sites', ['code' => 'MAIN', 'name' => 'dup'])->assertStatus(422);
        $this->actingAs($admin, 'web')->postJson('/api/v1/sites', ['code' => 'has space', 'name' => 'x'])->assertStatus(422);
        $this->actingAs($admin, 'web')->postJson("/api/v1/sites/{$site->uuid}/zones", ['code' => 'GATE-1', 'name' => 'dup'])->assertStatus(422);
        // The same zone code is fine on another site.
        $this->actingAs($admin, 'web')->postJson("/api/v1/sites/{$other->uuid}/zones", ['code' => 'GATE-1', 'name' => 'ok'])->assertStatus(201);

        $this->actingAs($admin, 'web')->putJson("/api/v1/sites/{$site->uuid}", ['code' => 'NEW'])->assertStatus(422);
        $this->actingAs($admin, 'web')->putJson("/api/v1/zones/{$zone->uuid}", ['code' => 'NEW'])->assertStatus(422);
        $this->actingAs($admin, 'web')->putJson("/api/v1/sites/{$site->uuid}", ['timezone' => 'Mars/Base'])->assertStatus(422);
    }

    public function test_update_and_deactivate_site_and_zone_are_audited(): void
    {
        $admin = $this->userWith(['system.view', 'system.manage']);
        $site = Site::factory()->create(['name' => 'Old']);
        $zone = Zone::factory()->create(['site_id' => $site->id, 'name' => 'Old gate']);

        $this->actingAs($admin, 'web')->putJson("/api/v1/sites/{$site->uuid}", ['name' => 'อาคาร 2', 'status' => 'INACTIVE'])
            ->assertOk()->assertJsonPath('data.name', 'อาคาร 2')->assertJsonPath('data.status', 'INACTIVE');
        $this->actingAs($admin, 'web')->putJson("/api/v1/zones/{$zone->uuid}", ['name' => 'ประตูหลัง', 'zone_type' => 'GATE'])
            ->assertOk()->assertJsonPath('data.zones.0.name', 'ประตูหลัง');

        $audit = AuditLog::where('action', 'location.site_updated')->where('entity_id', $site->uuid)->firstOrFail();
        $this->assertSame('Old', $audit->old_values['name']);
        $this->assertSame('INACTIVE', $audit->new_values['status']);
        $this->assertTrue(AuditLog::where('action', 'location.zone_updated')->where('entity_id', $zone->uuid)->exists());

        // Saving with no change writes no audit row.
        $this->actingAs($admin, 'web')->putJson("/api/v1/zones/{$zone->uuid}", ['name' => 'ประตูหลัง'])->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'location.zone_updated')->count());

        $this->actingAs($admin, 'web')->getJson('/api/v1/sites?status=ACTIVE')->assertOk()->assertJsonCount(0, 'data');
    }
}
