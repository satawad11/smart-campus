<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\EventDailySummary;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Zone;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RPT-012 — Specialized reports:
 * GET /reports/review
 * GET /reports/unknown
 * GET /reports/camera
 * GET /reports/ai-node
 */
class SpecializedReportsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'RPT_'.uniqid(), 'name' => 'Reporter', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_review_report_filters_review_events(): void
    {
        $user = $this->userWith(['report.view']);
        $camera = Camera::factory()->create(['name' => 'South Gate Cam']);

        EventDailySummary::factory()->create([
            'summary_date' => '2026-05-10',
            'camera_id' => $camera->id,
            'decision' => 'REVIEW',
            'event_count' => 7,
        ]);
        EventDailySummary::factory()->create([
            'summary_date' => '2026-05-10',
            'camera_id' => $camera->id,
            'decision' => 'ALLOW',
            'event_count' => 50,
        ]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/reports/review?from=2026-05-01&to=2026-05-31');

        $res->assertOk();
        $res->assertJsonPath('data.total_reviews', 7);
        $camItem = collect($res->json('data.by_camera'))->firstWhere('camera_uuid', $camera->uuid);
        $this->assertNotNull($camItem);
        $this->assertSame(7, $camItem['count']);
    }

    public function test_unknown_report_distinguishes_events_from_unique_people(): void
    {
        $user = $this->userWith(['report.view']);
        $camera = Camera::factory()->create(['name' => 'Perimeter Cam']);

        EventDailySummary::factory()->create([
            'summary_date' => '2026-05-12',
            'camera_id' => $camera->id,
            'decision' => 'UNKNOWN',
            'event_count' => 14,
        ]);

        $res = $this->actingAs($user, 'web')->getJson('/api/v1/reports/unknown?from=2026-05-01&to=2026-05-31');

        $res->assertOk();
        $res->assertJsonPath('data.total_unknowns', 14);
        $this->assertStringContainsString('Unknown events represent unrecognized faces', $res->json('data.note'));
    }

    public function test_camera_report_lists_camera_metrics_and_status(): void
    {
        $user = $this->userWith(['report.view']);
        $site = Site::factory()->create(['name' => 'Main Campus']);
        $zone = Zone::factory()->create(['site_id' => $site->id, 'name' => 'Entry Zone']);
        $camera = Camera::factory()->create([
            'site_id' => $site->id,
            'zone_id' => $zone->id,
            'name' => 'Gate 1 Camera',
        ]);

        EventDailySummary::factory()->create([
            'site_id' => $site->id,
            'camera_id' => $camera->id,
            'summary_date' => '2026-05-15',
            'decision' => 'ALLOW',
            'event_type' => 'FACE_RECOGNIZED',
            'event_count' => 20,
            'unique_person_count' => 15,
        ]);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/reports/camera?from=2026-05-01&to=2026-05-31&site_uuid={$site->uuid}");

        $res->assertOk();
        $cameras = $res->json('data.cameras');
        $this->assertCount(1, $cameras);
        $this->assertSame($camera->uuid, $cameras[0]['camera_uuid']);
        $this->assertSame('Gate 1 Camera', $cameras[0]['name']);
        $this->assertSame('Main Campus', $cameras[0]['site_name']);
        $this->assertSame('Entry Zone', $cameras[0]['zone_name']);
        $this->assertSame(20, $cameras[0]['totals']['detected']);
        $this->assertSame(15, $cameras[0]['totals']['unique_persons_recognized']);
    }

    public function test_ai_node_report_lists_hardware_and_load_status(): void
    {
        $user = $this->userWith(['report.view']);
        $site = Site::factory()->create(['name' => 'North Facility']);
        $node = AiNode::factory()->create([
            'site_id' => $site->id,
            'name' => 'Edge Worker 1',
            'gpu_name' => 'NVIDIA RTX 4090',
        ]);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/reports/ai-node?site_uuid={$site->uuid}");

        $res->assertOk();
        $nodes = $res->json('data.nodes');
        $this->assertCount(1, $nodes);
        $this->assertSame($node->uuid, $nodes[0]['node_uuid']);
        $this->assertSame('Edge Worker 1', $nodes[0]['name']);
        $this->assertSame('North Facility', $nodes[0]['site_name']);
        $this->assertSame('NVIDIA RTX 4090', $nodes[0]['gpu_name']);
    }
}
