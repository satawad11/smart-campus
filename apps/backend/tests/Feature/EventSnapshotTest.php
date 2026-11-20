<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\AuditLog;
use App\Models\Camera;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Camera\AiNodeCredentialService;
use App\Services\System\RetentionCleanupService;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EVT-025 (ACR-EVT-24, approved 2026-11-20) — event face crop:
 * node upload validation, private storage, scope-checked and audited
 * viewing, no image or key in ordinary event JSON, and retention.
 */
class EventSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(EventTypeSeeder::class);
        Storage::fake('event_snapshots');
    }

    /** A minimal JPEG header (SOI, APP0, SOF0, EOI) with the given size. */
    private function jpeg(int $width, int $height): string
    {
        return "\xFF\xD8"
            ."\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            ."\xFF\xC0\x00\x11\x08".pack('n', $height).pack('n', $width)
            ."\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01"
            ."\xFF\xD9";
    }

    private function bearerFor(AiNode $node): array
    {
        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);

        return ['Authorization' => "Bearer {$token}"];
    }

    /** @return array{0: AiNode, 1: AccessEvent} */
    private function nodeEvent(string $decision = 'UNKNOWN', array $eventOverrides = []): array
    {
        $node = AiNode::factory()->create();
        $camera = Camera::factory()->create(['ai_node_id' => $node->id]);
        $event = AccessEvent::factory()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'site_id' => $camera->site_id,
            'zone_id' => $camera->zone_id,
            'camera_id' => $camera->id,
            'ai_node_id' => $node->id,
        ], $eventOverrides));
        AccessDecision::factory()->for($event, 'event')->create(['decision' => $decision]);

        return [$node, $event];
    }

    private function upload(AiNode $node, AccessEvent|string $event, string $bytes, string $name = 'face.jpg')
    {
        $uuid = $event instanceof AccessEvent ? $event->uuid : $event;

        return $this->withHeaders($this->bearerFor($node))->post(
            "/internal/v1/events/{$uuid}/snapshot",
            ['image' => UploadedFile::fake()->createWithContent($name, $bytes)],
            ['Accept' => 'application/json'],
        );
    }

    private function userWith(array $codes, ?int $siteId = null): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'SNAP_'.uniqid(), 'name' => 'Snapshot', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'site_id' => $siteId]);

        return $user;
    }

    private function withStoredCrop(AccessEvent $event): string
    {
        $path = 'test/'.$event->uuid.'.jpg';
        Storage::disk('event_snapshots')->put($path, $this->jpeg(120, 140));
        $event->update(['snapshot_path' => $path]);

        return $path;
    }

    // --- Node upload ---------------------------------------------------------

    public function test_upload_requires_the_node_token(): void
    {
        [, $event] = $this->nodeEvent();
        $this->post("/internal/v1/events/{$event->uuid}/snapshot", [], ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_node_stores_a_face_crop_privately_and_the_response_has_no_key_or_image(): void
    {
        [$node, $event] = $this->nodeEvent('UNKNOWN');

        $res = $this->upload($node, $event, $this->jpeg(160, 200));

        $res->assertStatus(201)
            ->assertJsonPath('data.event_uuid', $event->uuid)
            ->assertJsonPath('data.snapshot_available', true);
        $this->assertStringNotContainsString('.jpg', $res->getContent());

        $path = $event->fresh()->snapshot_path;
        $this->assertNotNull($path);
        Storage::disk('event_snapshots')->assertExists($path);
        $this->assertStringContainsString($event->uuid, $path);

        $audit = AuditLog::where('action', 'EVENT_SNAPSHOT_STORED')->first();
        $this->assertNotNull($audit);
        $this->assertSame($event->uuid, $audit->entity_id);
    }

    public function test_review_and_deny_events_are_eligible_but_allow_is_not(): void
    {
        foreach (['REVIEW' => 201, 'DENY' => 201, 'ALLOW' => 422] as $decision => $status) {
            [$node, $event] = $this->nodeEvent($decision);
            $res = $this->upload($node, $event, $this->jpeg(100, 100));
            $res->assertStatus($status);
            if ($status === 422) {
                $res->assertJsonPath('error.code', 'EVENT_SNAPSHOT_NOT_ELIGIBLE');
            }
        }
    }

    public function test_eligibility_uses_the_system_decision_not_a_later_review(): void
    {
        [$node, $event] = $this->nodeEvent('ALLOW');
        AccessDecision::factory()->for($event, 'event')->create(['decision' => 'DENY']);

        $this->upload($node, $event, $this->jpeg(100, 100))
            ->assertStatus(422)->assertJsonPath('error.code', 'EVENT_SNAPSHOT_NOT_ELIGIBLE');
    }

    public function test_another_nodes_event_and_a_missing_event_both_answer_404(): void
    {
        [, $event] = $this->nodeEvent();
        $otherNode = AiNode::factory()->create();

        $this->upload($otherNode, $event, $this->jpeg(100, 100))->assertStatus(404)->assertJsonPath('error.code', 'EVENT_NOT_FOUND');
        $this->upload($otherNode, (string) Str::uuid(), $this->jpeg(100, 100))->assertStatus(404)->assertJsonPath('error.code', 'EVENT_NOT_FOUND');
        $this->assertNull($event->fresh()->snapshot_path);
    }

    public function test_camera_reassigned_away_from_the_node_is_rejected(): void
    {
        [$node, $event] = $this->nodeEvent();
        $event->camera->update(['ai_node_id' => AiNode::factory()->create()->id]);

        $this->upload($node, $event, $this->jpeg(100, 100))
            ->assertStatus(422)->assertJsonPath('error.code', 'CAMERA_NOT_ASSIGNED_TO_NODE');
    }

    public function test_rejects_non_jpeg_full_frames_tiny_images_and_oversized_files(): void
    {
        [$node, $event] = $this->nodeEvent();

        $png = "\x89PNG\r\n\x1a\n".str_repeat("\x00", 64);
        $this->upload($node, $event, $png, 'face.png')->assertStatus(422);

        $this->upload($node, $event, $this->jpeg(1920, 1080))
            ->assertStatus(422)->assertJsonPath('error.code', 'EVENT_SNAPSHOT_TOO_LARGE');

        $this->upload($node, $event, $this->jpeg(8, 8))
            ->assertStatus(422)->assertJsonPath('error.code', 'EVENT_SNAPSHOT_INVALID_IMAGE');

        config(['event.snapshot.max_bytes' => 2048]);
        $big = $this->jpeg(100, 100).str_repeat("\x00", 4096);
        $this->upload($node, $event, $big)->assertStatus(422);

        $this->assertNull($event->fresh()->snapshot_path);
        $this->assertSame([], Storage::disk('event_snapshots')->allFiles());
    }

    public function test_a_second_upload_is_rejected_and_keeps_the_first_image(): void
    {
        [$node, $event] = $this->nodeEvent();
        $this->upload($node, $event, $this->jpeg(100, 100))->assertStatus(201);
        $first = $event->fresh()->snapshot_path;

        $this->upload($node, $event, $this->jpeg(120, 120))
            ->assertStatus(409)->assertJsonPath('error.code', 'EVENT_SNAPSHOT_ALREADY_STORED');

        $this->assertSame($first, $event->fresh()->snapshot_path);
        $this->assertCount(1, Storage::disk('event_snapshots')->allFiles());
    }

    public function test_upload_after_the_window_is_rejected(): void
    {
        [$node, $event] = $this->nodeEvent('UNKNOWN', ['received_at' => now()->subMinutes(30)]);

        $this->upload($node, $event, $this->jpeg(100, 100))
            ->assertStatus(422)->assertJsonPath('error.code', 'EVENT_SNAPSHOT_WINDOW_EXPIRED');
    }

    // --- Ordinary event JSON --------------------------------------------------

    public function test_event_json_has_only_a_boolean_never_the_key_or_bytes(): void
    {
        [, $event] = $this->nodeEvent();
        $path = $this->withStoredCrop($event);
        $user = $this->userWith(['event.view']);

        $show = $this->actingAs($user, 'web')->getJson("/api/v1/events/{$event->uuid}");
        $show->assertOk()->assertJsonPath('data.snapshot_available', true);
        $this->assertStringNotContainsString($path, $show->getContent());
        $this->assertArrayNotHasKey('snapshot_path', $show->json('data'));
        $this->assertArrayNotHasKey('image_base64', $show->json('data'));

        $list = $this->actingAs($user, 'web')->getJson('/api/v1/events');
        $list->assertOk()->assertJsonPath('data.0.snapshot_available', true);
        $this->assertStringNotContainsString($path, $list->getContent());
    }

    public function test_event_without_a_crop_reports_unavailable(): void
    {
        [, $event] = $this->nodeEvent();
        $user = $this->userWith(['event.view']);

        $this->actingAs($user, 'web')->getJson("/api/v1/events/{$event->uuid}")
            ->assertOk()->assertJsonPath('data.snapshot_available', false);
    }

    // --- Viewing ------------------------------------------------------------

    public function test_authorized_operator_views_the_crop_and_each_view_is_audited(): void
    {
        [, $event] = $this->nodeEvent();
        $this->withStoredCrop($event);
        $user = $this->userWith(['event.view', 'camera.live']);

        $res = $this->actingAs($user, 'web')->getJson("/api/v1/events/{$event->uuid}/snapshot");
        $res->assertOk()
            ->assertJsonPath('data.content_type', 'image/jpeg')
            ->assertJsonPath('data.image_base64', base64_encode($this->jpeg(120, 140)));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));

        $this->actingAs($user, 'web')->getJson("/api/v1/events/{$event->uuid}/snapshot")->assertOk();

        $views = AuditLog::where('action', 'EVENT_SNAPSHOT_VIEWED')->get();
        $this->assertCount(2, $views);
        $this->assertSame($event->uuid, $views[0]->entity_id);
        $this->assertSame($user->id, $views[0]->user_id);
    }

    public function test_face_view_also_grants_viewing(): void
    {
        [, $event] = $this->nodeEvent();
        $this->withStoredCrop($event);

        $this->actingAs($this->userWith(['event.view', 'face.view']), 'web')
            ->getJson("/api/v1/events/{$event->uuid}/snapshot")->assertOk();
    }

    public function test_event_view_alone_or_a_face_permission_alone_is_forbidden(): void
    {
        [, $event] = $this->nodeEvent();
        $this->withStoredCrop($event);

        $this->actingAs($this->userWith(['event.view']), 'web')
            ->getJson("/api/v1/events/{$event->uuid}/snapshot")->assertStatus(403);
        $this->actingAs($this->userWith(['camera.live', 'face.view']), 'web')
            ->getJson("/api/v1/events/{$event->uuid}/snapshot")->assertStatus(403);

        $this->assertSame(0, AuditLog::where('action', 'EVENT_SNAPSHOT_VIEWED')->count());
    }

    public function test_viewing_requires_authentication(): void
    {
        [, $event] = $this->nodeEvent();
        $this->withStoredCrop($event);

        $this->getJson("/api/v1/events/{$event->uuid}/snapshot")->assertStatus(401);
    }

    public function test_out_of_scope_event_answers_404_and_is_not_audited(): void
    {
        [, $event] = $this->nodeEvent();
        $this->withStoredCrop($event);
        $otherSite = Site::factory()->create();
        $user = $this->userWith(['event.view', 'camera.live'], $otherSite->id);

        $this->actingAs($user, 'web')->getJson("/api/v1/events/{$event->uuid}/snapshot")->assertStatus(404);
        $this->assertSame(0, AuditLog::where('action', 'EVENT_SNAPSHOT_VIEWED')->count());

        $inScope = $this->userWith(['event.view', 'camera.live'], $event->site_id);
        $this->actingAs($inScope, 'web')->getJson("/api/v1/events/{$event->uuid}/snapshot")->assertOk();
    }

    public function test_event_without_a_crop_or_with_a_missing_object_answers_404(): void
    {
        [, $event] = $this->nodeEvent();
        $user = $this->userWith(['event.view', 'camera.live']);

        $this->actingAs($user, 'web')->getJson("/api/v1/events/{$event->uuid}/snapshot")
            ->assertStatus(404)->assertJsonPath('error.code', 'EVENT_SNAPSHOT_NOT_AVAILABLE');

        $event->update(['snapshot_path' => 'gone/missing.jpg']);
        $this->actingAs($user, 'web')->getJson("/api/v1/events/{$event->uuid}/snapshot")
            ->assertStatus(404)->assertJsonPath('error.code', 'EVENT_SNAPSHOT_NOT_AVAILABLE');

        $this->assertSame(0, AuditLog::where('action', 'EVENT_SNAPSHOT_VIEWED')->count());
    }

    // --- Retention ------------------------------------------------------------

    private function setRetention(string $key, int $days): void
    {
        SystemSetting::updateOrCreate(
            ['key' => $key],
            ['group' => 'RETENTION', 'type' => 'integer', 'value' => (string) $days, 'is_public' => false],
        );
    }

    public function test_retention_deletes_expired_crops_and_clears_the_reference_but_keeps_the_event(): void
    {
        $this->setRetention('retention.snapshots_days', 30);
        [, $old] = $this->nodeEvent('UNKNOWN', ['occurred_at' => now()->subDays(40)]);
        [, $recent] = $this->nodeEvent('UNKNOWN', ['occurred_at' => now()->subDays(5)]);
        $oldPath = $this->withStoredCrop($old);
        $recentPath = $this->withStoredCrop($recent);

        $service = app(RetentionCleanupService::class);
        $this->assertSame(1, $service->cleanupSnapshots(dryRun: true));
        Storage::disk('event_snapshots')->assertExists($oldPath);

        $this->assertSame(1, $service->cleanupSnapshots());

        Storage::disk('event_snapshots')->assertMissing($oldPath);
        Storage::disk('event_snapshots')->assertExists($recentPath);
        $this->assertNull($old->fresh()->snapshot_path);
        $this->assertNotNull($old->fresh(), 'the event itself is kept');
        $this->assertSame($recentPath, $recent->fresh()->snapshot_path);
    }

    public function test_event_retention_also_removes_the_crop_object(): void
    {
        $this->setRetention('retention.events_days', 10);
        [, $event] = $this->nodeEvent('UNKNOWN', ['occurred_at' => now()->subDays(20)]);
        $path = $this->withStoredCrop($event);

        app(RetentionCleanupService::class)->cleanupEvents();

        $this->assertNull(AccessEvent::find($event->id));
        Storage::disk('event_snapshots')->assertMissing($path);
    }
}
