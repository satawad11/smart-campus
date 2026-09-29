<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemAlert;
use App\Models\User;
use App\Models\UserRole;
use App\Services\System\AlertLifecycleService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemMonitoringAndIncidentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create([
            'code' => 'ROLE_'.uniqid(),
            'name' => 'Test Role',
            'system_role' => false,
            'status' => RoleStatus::ACTIVE,
        ]);

        $permissionIds = Permission::whereIn('code', $permissions)->pluck('id');
        $role->permissions()->attach($permissionIds);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_system_health_endpoint_returns_aggregated_metrics(): void
    {
        $viewer = $this->createUserWithPermissions(['system.view']);

        $response = $this->actingAs($viewer)
            ->getJson('/api/v1/system/health')
            ->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'status',
                    'timestamp',
                    'core_services' => ['backend', 'database', 'redis', 'storage', 'ai_service'],
                    'edge_devices' => ['cameras', 'ai_nodes'],
                    'alerts' => ['open_total', 'open_critical'],
                ],
            ]);

        $this->assertTrue($response->json('success'));
    }

    public function test_alert_lifecycle_flow(): void
    {
        $manager = $this->createUserWithPermissions(['system.view', 'system.manage']);

        /** @var AlertLifecycleService $alertService */
        $alertService = app(AlertLifecycleService::class);
        $alert = $alertService->triggerAlert(
            type: 'CAMERA_DOWN',
            severity: 'CRITICAL',
            title: 'Camera Gate 1 Disconnected',
            message: 'No RTSP frames received for 3 minutes',
            source: 'CAM-GATE-01'
        );

        $this->assertSame(SystemAlert::STATUS_OPEN, $alert->status);

        // List alerts API
        $this->actingAs($manager)
            ->getJson('/api/v1/system/alerts')
            ->assertStatus(200)
            ->assertJsonFragment(['title' => 'Camera Gate 1 Disconnected']);

        // Acknowledge alert API
        $this->actingAs($manager)
            ->postJson("/api/v1/system/alerts/{$alert->id}/acknowledge")
            ->assertStatus(200)
            ->assertJsonPath('data.status', SystemAlert::STATUS_ACKNOWLEDGED);

        // Resolve alert API
        $this->actingAs($manager)
            ->postJson("/api/v1/system/alerts/{$alert->id}/resolve", ['notes' => 'Cable reconnected'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', SystemAlert::STATUS_RESOLVED);

        $this->assertDatabaseHas('system_alerts', [
            'id' => $alert->id,
            'status' => SystemAlert::STATUS_RESOLVED,
            'resolution_notes' => 'Cable reconnected',
        ]);
    }

    public function test_system_incident_management_workflow(): void
    {
        $manager = $this->createUserWithPermissions(['system.view', 'system.manage']);

        // Create incident
        $createResponse = $this->actingAs($manager)
            ->postJson('/api/v1/system/incidents', [
                'title' => 'Main Gateway Switch Power Flap',
                'severity' => 'SEV1',
                'description' => 'Power failure on PoE switch affecting 4 entrance cameras.',
                'impact_summary' => 'Entrance recognition unavailable for 8 minutes.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.title', 'Main Gateway Switch Power Flap')
            ->assertJsonPath('data.severity', 'SEV1');

        $incidentId = $createResponse->json('data.id');

        // Update incident to RESOLVED with post-mortem details
        $this->actingAs($manager)
            ->putJson("/api/v1/system/incidents/{$incidentId}", [
                'status' => 'RESOLVED',
                'root_cause' => 'UPS battery trip during scheduled transfer test.',
                'resolution_summary' => 'Secondary power rail engaged, breaker reset.',
                'action_items' => ['Replace UPS battery module', 'Schedule transfer drill'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'RESOLVED')
            ->assertJsonPath('data.root_cause', 'UPS battery trip during scheduled transfer test.');

        $this->assertDatabaseHas('system_incidents', [
            'id' => $incidentId,
            'status' => 'RESOLVED',
            'severity' => 'SEV1',
        ]);
    }
}
