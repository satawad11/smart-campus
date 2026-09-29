<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleBasedUserAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            PermissionSeeder::class,
            RoleSeeder::class,
            RolePermissionSeeder::class,
            SystemSettingSeeder::class,
        ]);
    }

    private function createActorForRole(string $roleCode): User
    {
        $user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'username' => strtolower($roleCode).'_user',
        ]);

        $role = Role::where('code', $roleCode)->firstOrFail();
        UserRole::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    /**
     * Scenario 1: System Admin has technical access and system settings control.
     */
    public function test_system_admin_can_access_settings_and_health_monitoring(): void
    {
        $admin = $this->createActorForRole('SYSTEM_ADMIN');

        $this->actingAs($admin)
            ->getJson('/api/v1/settings')
            ->assertStatus(200);

        $this->actingAs($admin)
            ->getJson('/api/v1/system/health')
            ->assertStatus(200);

        $this->actingAs($admin)
            ->getJson('/api/v1/system/alerts')
            ->assertStatus(200);
    }

    /**
     * Scenario 2: Security Officer has camera monitoring and live event access,
     * but is forbidden from modifying users, altering system settings, or changing student status.
     */
    public function test_security_officer_boundary_enforcement(): void
    {
        $security = $this->createActorForRole('SECURITY_OFFICER');

        // Can access cameras and events
        $this->actingAs($security)
            ->getJson('/api/v1/cameras')
            ->assertStatus(200);

        $this->actingAs($security)
            ->getJson('/api/v1/events')
            ->assertStatus(200);

        // Forbidden from user administration (Rule: Security officer cannot create users)
        $this->actingAs($security)
            ->postJson('/api/v1/users', [
                'username' => 'illegal_user',
                'name' => 'Illegal',
                'email' => 'illegal@campus.local',
                'password' => 'Secret123!',
            ])
            ->assertStatus(403);

        // Forbidden from system settings modifications
        $this->actingAs($security)
            ->putJson('/api/v1/settings/SECURITY', [
                'security.session_timeout_minutes' => 60,
            ])
            ->assertStatus(403);
    }

    /**
     * Scenario 3: Discipline Officer can view students, enroll faces, review events, and export reports,
     * but cannot modify system configuration or create technical roles.
     */
    public function test_discipline_officer_boundary_enforcement(): void
    {
        $discipline = $this->createActorForRole('DISCIPLINE_OFFICER');

        // Can access students list
        $this->actingAs($discipline)
            ->getJson('/api/v1/students')
            ->assertStatus(200);

        // Can view reports
        $this->actingAs($discipline)
            ->getJson('/api/v1/reports/daily')
            ->assertStatus(200);

        // Cannot view or modify system settings
        $this->actingAs($discipline)
            ->getJson('/api/v1/settings')
            ->assertStatus(403);

        $this->actingAs($discipline)
            ->getJson('/api/v1/system/health')
            ->assertStatus(403);
    }

    /**
     * Scenario 4: Executive Viewer has aggregate reporting and dashboard access,
     * but is denied from biometric face enrollment, camera CRUD, and user management.
     */
    public function test_executive_viewer_boundary_enforcement(): void
    {
        $exec = $this->createActorForRole('EXECUTIVE_VIEWER');

        // Can view dashboard summary
        $this->actingAs($exec)
            ->getJson('/api/v1/dashboard/summary')
            ->assertStatus(200);

        // Denied from cameras
        $this->actingAs($exec)
            ->getJson('/api/v1/cameras')
            ->assertStatus(403);

        // Denied from system settings
        $this->actingAs($exec)
            ->getJson('/api/v1/settings')
            ->assertStatus(403);

        // Denied from user management
        $this->actingAs($exec)
            ->getJson('/api/v1/users')
            ->assertStatus(403);
    }
}
