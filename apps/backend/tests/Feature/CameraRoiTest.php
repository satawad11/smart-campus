<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Camera;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CAM-016 — PATCH /cameras/{uuid}/roi region-of-interest configuration.
 */
class CameraRoiTest extends TestCase
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
        $role = Role::create(['code' => 'ROI_'.uniqid(), 'name' => 'Camera ROI Admin', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function triangle(): array
    {
        return [
            ['x' => 0.1, 'y' => 0.1],
            ['x' => 0.9, 'y' => 0.1],
            ['x' => 0.5, 'y' => 0.9],
        ];
    }

    public function test_requires_authentication(): void
    {
        $camera = Camera::factory()->create();

        $this->patchJson("/api/v1/cameras/{$camera->uuid}/roi", ['points' => $this->triangle()])
            ->assertStatus(401);
    }

    public function test_requires_camera_update_permission(): void
    {
        $user = $this->userWith(['camera.view']);
        $camera = Camera::factory()->create();

        $this->actingAs($user, 'web')
            ->patchJson("/api/v1/cameras/{$camera->uuid}/roi", ['points' => $this->triangle()])
            ->assertStatus(403);
    }

    public function test_sets_roi_polygon(): void
    {
        $user = $this->userWith(['camera.update']);
        $camera = Camera::factory()->create(['roi_polygon' => null]);

        $res = $this->actingAs($user, 'web')
            ->patchJson("/api/v1/cameras/{$camera->uuid}/roi", ['points' => $this->triangle()]);

        $res->assertOk();
        $res->assertJsonCount(3, 'data.roi');

        $camera->refresh();
        $this->assertCount(3, $camera->roi_polygon);
    }

    public function test_clears_roi_polygon_with_null_points(): void
    {
        $user = $this->userWith(['camera.update']);
        $camera = Camera::factory()->create(['roi_polygon' => $this->triangle()]);

        $res = $this->actingAs($user, 'web')
            ->patchJson("/api/v1/cameras/{$camera->uuid}/roi", ['points' => null]);

        $res->assertOk();
        $res->assertJsonPath('data.roi', null);

        $camera->refresh();
        $this->assertNull($camera->roi_polygon);
    }

    public function test_rejects_fewer_than_three_points(): void
    {
        $user = $this->userWith(['camera.update']);
        $camera = Camera::factory()->create();

        $this->actingAs($user, 'web')
            ->patchJson("/api/v1/cameras/{$camera->uuid}/roi", [
                'points' => [['x' => 0.1, 'y' => 0.1], ['x' => 0.5, 'y' => 0.5]],
            ])
            ->assertStatus(422);
    }

    public function test_rejects_out_of_bounds_coordinates(): void
    {
        $user = $this->userWith(['camera.update']);
        $camera = Camera::factory()->create();

        $this->actingAs($user, 'web')
            ->patchJson("/api/v1/cameras/{$camera->uuid}/roi", [
                'points' => [
                    ['x' => -0.1, 'y' => 0.1],
                    ['x' => 0.5, 'y' => 0.5],
                    ['x' => 0.9, 'y' => 0.9],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_records_audit_without_leaking_raw_point_values(): void
    {
        $user = $this->userWith(['camera.update']);
        $camera = Camera::factory()->create();

        $this->actingAs($user, 'web')
            ->patchJson("/api/v1/cameras/{$camera->uuid}/roi", ['points' => $this->triangle()])
            ->assertOk();

        $log = AuditLog::where('action', 'CAMERA_ROI_UPDATED')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(true, $log->new_values['roi_configured']);
        $this->assertArrayNotHasKey('points', $log->new_values);
    }
}
