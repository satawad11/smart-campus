<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Enums\UserStatus;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\ReportExport;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Zone;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V1 audit A-05/A-06: SITE- and ZONE-scoped roles must only reach data of their
 * own site/zone on every REST endpoint (not only the Reverb channel), and
 * report exports belong to their creator. Out-of-scope records answer 404.
 */
class DataScopeEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    private Zone $zoneA;

    private AccessEvent $eventA;

    private AccessEvent $eventB;

    private Camera $cameraA;

    private Camera $cameraB;

    private AiNode $nodeB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);

        $this->siteA = Site::factory()->create();
        $this->siteB = Site::factory()->create();
        $this->zoneA = Zone::factory()->create(['site_id' => $this->siteA->id]);
        $zoneB = Zone::factory()->create(['site_id' => $this->siteB->id]);
        $nodeA = AiNode::factory()->create(['site_id' => $this->siteA->id]);
        $this->nodeB = AiNode::factory()->create(['site_id' => $this->siteB->id]);
        $this->cameraA = Camera::factory()->create(['site_id' => $this->siteA->id, 'zone_id' => $this->zoneA->id, 'ai_node_id' => $nodeA->id, 'status' => DeviceStatus::ACTIVE]);
        $this->cameraB = Camera::factory()->create(['site_id' => $this->siteB->id, 'zone_id' => $zoneB->id, 'ai_node_id' => $this->nodeB->id, 'status' => DeviceStatus::ACTIVE]);

        $this->eventA = AccessEvent::factory()->create(['site_id' => $this->siteA->id, 'zone_id' => $this->zoneA->id, 'camera_id' => $this->cameraA->id]);
        $this->eventB = AccessEvent::factory()->create(['site_id' => $this->siteB->id, 'zone_id' => $zoneB->id, 'camera_id' => $this->cameraB->id]);
        foreach ([$this->eventA, $this->eventB] as $event) {
            AccessDecision::factory()->create(['event_id' => $event->id, 'decision' => 'REVIEW']);
        }
    }

    private function userWith(string $roleCode, ?int $siteId = null, ?int $zoneId = null): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        UserRole::create([
            'user_id' => $user->id,
            'role_id' => Role::where('code', $roleCode)->value('id'),
            'site_id' => $siteId,
            'zone_id' => $zoneId,
        ]);

        return $user;
    }

    private function uuids($response): array
    {
        return collect($response->json('data'))->pluck('uuid')->all();
    }

    public function test_site_scoped_officer_sees_only_own_site_events(): void
    {
        $officer = $this->userWith('SECURITY_OFFICER', $this->siteA->id);

        $list = $this->actingAs($officer, 'web')->getJson('/api/v1/events')->assertOk();
        $this->assertSame([$this->eventA->uuid], $this->uuids($list));

        $this->actingAs($officer, 'web')->getJson("/api/v1/events/{$this->eventA->uuid}")->assertOk();
        $this->actingAs($officer, 'web')->getJson("/api/v1/events/{$this->eventB->uuid}")->assertNotFound();
    }

    public function test_site_scoped_officer_cannot_review_other_site_event(): void
    {
        // DISCIPLINE_OFFICER holds event.review (SECURITY_OFFICER does not)
        $officer = $this->userWith('DISCIPLINE_OFFICER', $this->siteA->id);

        $this->actingAs($officer, 'web')->postJson("/api/v1/events/{$this->eventB->uuid}/review", [
            'decision' => 'ALLOW',
            'reason_code' => 'OFFICER_VERIFIED',
        ])->assertNotFound();

        $this->assertSame(1, AccessDecision::where('event_id', $this->eventB->id)->count());
    }

    public function test_zone_scoped_user_sees_only_own_zone(): void
    {
        $officer = $this->userWith('SECURITY_OFFICER', $this->siteA->id, $this->zoneA->id);
        $otherZoneEvent = AccessEvent::factory()->create(['site_id' => $this->siteA->id, 'zone_id' => Zone::factory()->create(['site_id' => $this->siteA->id])->id]);

        $list = $this->actingAs($officer, 'web')->getJson('/api/v1/events')->assertOk();
        $this->assertSame([$this->eventA->uuid], $this->uuids($list));
        $this->actingAs($officer, 'web')->getJson("/api/v1/events/{$otherZoneEvent->uuid}")->assertNotFound();
    }

    public function test_history_and_person_timeline_are_scoped(): void
    {
        $viewer = $this->userWith('DISCIPLINE_OFFICER', $this->siteA->id);

        $history = $this->actingAs($viewer, 'web')->getJson('/api/v1/history/search')->assertOk();
        $this->assertNotContains($this->eventB->uuid, $this->uuids($history));
        $this->assertContains($this->eventA->uuid, $this->uuids($history));
    }

    public function test_cameras_and_ai_nodes_are_scoped(): void
    {
        $admin = $this->userWith('SYSTEM_ADMIN', $this->siteA->id);

        $cameras = $this->actingAs($admin, 'web')->getJson('/api/v1/cameras')->assertOk();
        $this->assertSame([$this->cameraA->uuid], $this->uuids($cameras));
        $this->actingAs($admin, 'web')->getJson("/api/v1/cameras/{$this->cameraB->uuid}")->assertNotFound();
        $this->actingAs($admin, 'web')->putJson("/api/v1/cameras/{$this->cameraB->uuid}", ['name' => 'hijack'])->assertNotFound();
        $this->assertNotSame('hijack', $this->cameraB->fresh()->name);

        $this->actingAs($admin, 'web')->getJson("/api/v1/ai-nodes/{$this->nodeB->uuid}")->assertNotFound();
        $this->actingAs($admin, 'web')->postJson("/api/v1/ai-nodes/{$this->nodeB->uuid}/credentials")->assertNotFound();
        $this->assertNotContains($this->nodeB->uuid, $this->uuids($this->actingAs($admin, 'web')->getJson('/api/v1/ai-nodes')));
    }

    public function test_site_scoped_admin_cannot_create_camera_in_another_site(): void
    {
        $admin = $this->userWith('SYSTEM_ADMIN', $this->siteA->id);
        $zoneB = Zone::where('site_id', $this->siteB->id)->first();

        $this->actingAs($admin, 'web')->postJson('/api/v1/cameras', [
            'site_uuid' => $this->siteB->uuid,
            'zone_uuid' => $zoneB->uuid,
            'camera_code' => 'ROGUE',
            'name' => 'Rogue camera',
        ])->assertNotFound();
        $this->assertDatabaseMissing('cameras', ['camera_code' => 'ROGUE']);
    }

    public function test_reports_default_to_and_are_limited_to_the_scoped_site(): void
    {
        $viewer = $this->userWith('EXECUTIVE_VIEWER', $this->siteA->id);

        $this->actingAs($viewer, 'web')->getJson('/api/v1/reports/daily')->assertOk();
        $this->actingAs($viewer, 'web')->getJson('/api/v1/reports/daily?site_uuid='.$this->siteB->uuid)->assertNotFound();
        $this->actingAs($viewer, 'web')->getJson('/api/v1/dashboard/summary?site_uuid='.$this->siteB->uuid)->assertNotFound();
        $this->actingAs($viewer, 'web')->getJson('/api/v1/dashboard/events-by-hour?site_uuid='.$this->siteB->uuid)->assertNotFound();

        $global = $this->userWith('SUPER_ADMIN');
        $this->actingAs($global, 'web')->getJson('/api/v1/reports/daily?site_uuid='.$this->siteB->uuid)->assertOk();
    }

    public function test_export_filters_are_pinned_to_scope_and_exports_are_private(): void
    {
        $viewer = $this->userWith('DISCIPLINE_OFFICER', $this->siteA->id);

        $this->actingAs($viewer, 'web')->postJson('/api/v1/reports/exports', [
            'report_type' => 'daily', 'format' => 'CSV', 'filters' => ['site_uuid' => $this->siteB->uuid],
        ])->assertNotFound();

        $this->actingAs($viewer, 'web')->postJson('/api/v1/reports/exports', [
            'report_type' => 'daily', 'format' => 'CSV',
        ])->assertSuccessful();
        $this->assertSame($this->siteA->uuid, ReportExport::latest('id')->first()->filters['site_uuid']);

        $owner = $this->userWith('SUPER_ADMIN');
        $foreign = ReportExport::create([
            'user_id' => $owner->id, 'report_type' => 'daily', 'format' => ReportExportFormat::CSV,
            'filters' => [], 'status' => ReportExportStatus::COMPLETED, 'expires_at' => now()->addDay(),
        ]);
        $this->actingAs($viewer, 'web')->getJson("/api/v1/reports/exports/{$foreign->uuid}")->assertNotFound();

        // Export list never exposes numeric ids or internal storage keys
        $row = $this->actingAs($viewer, 'web')->getJson('/api/v1/reports/exports')->assertOk()->json('data.0');
        $this->assertArrayHasKey('uuid', $row);
        foreach (['id', 'user_id', 'file_path'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $row);
        }
        $this->actingAs($viewer, 'web')->get("/api/v1/reports/exports/{$foreign->uuid}/download")->assertNotFound();
    }

    public function test_global_role_still_sees_everything(): void
    {
        $admin = $this->userWith('SUPER_ADMIN');

        $list = $this->actingAs($admin, 'web')->getJson('/api/v1/events')->assertOk();
        $this->assertEqualsCanonicalizing([$this->eventA->uuid, $this->eventB->uuid], $this->uuids($list));
        $this->actingAs($admin, 'web')->getJson("/api/v1/cameras/{$this->cameraB->uuid}")->assertOk();
    }
}
