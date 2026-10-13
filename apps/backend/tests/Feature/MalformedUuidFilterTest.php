<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression: every `*_uuid` filter on the history, dashboard and report
 * endpoints must reject a malformed value with 422 VALIDATION_ERROR.
 *
 * On PostgreSQL the referenced columns are native uuid, so an `exists` rule
 * evaluated against a non-uuid string raises "invalid input syntax for type
 * uuid" (HTTP 500) unless `bail` + `uuid` reject the format first. The test
 * runs on both databases; only the PostgreSQL run can observe the 500.
 */
class MalformedUuidFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function reportViewer(): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'RPT_'.uniqid(), 'name' => 'Report Viewer', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', ['report.view'])->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function endpoints(): array
    {
        return [
            'history person_uuid' => ['/api/v1/history/search?person_uuid=not-a-uuid', 'person_uuid'],
            'history site_uuid' => ['/api/v1/history/search?site_uuid=not-a-uuid', 'site_uuid'],
            'history zone_uuid' => ['/api/v1/history/search?zone_uuid=not-a-uuid', 'zone_uuid'],
            'history camera_uuid' => ['/api/v1/history/search?camera_uuid=not-a-uuid', 'camera_uuid'],
            'dashboard summary' => ['/api/v1/dashboard/summary?site_uuid=not-a-uuid', 'site_uuid'],
            'dashboard by hour' => ['/api/v1/dashboard/events-by-hour?site_uuid=not-a-uuid', 'site_uuid'],
            'dashboard by decision' => ['/api/v1/dashboard/events-by-decision?site_uuid=not-a-uuid', 'site_uuid'],
            'daily report' => ['/api/v1/reports/daily?site_uuid=not-a-uuid', 'site_uuid'],
            'weekly report' => ['/api/v1/reports/weekly?site_uuid=not-a-uuid', 'site_uuid'],
            'monthly report' => ['/api/v1/reports/monthly?site_uuid=not-a-uuid', 'site_uuid'],
            'review report' => ['/api/v1/reports/review?site_uuid=not-a-uuid', 'site_uuid'],
            'unknown report' => ['/api/v1/reports/unknown?site_uuid=not-a-uuid', 'site_uuid'],
            'camera report' => ['/api/v1/reports/camera?site_uuid=not-a-uuid', 'site_uuid'],
            'ai-node report' => ['/api/v1/reports/ai-node?site_uuid=not-a-uuid', 'site_uuid'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_malformed_uuid_filter_returns_422_not_500(string $url, string $field): void
    {
        $res = $this->actingAs($this->reportViewer(), 'web')->getJson($url);

        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey($field, $res->json('error.fields'), "expected a validation error for {$field}");
    }
}
