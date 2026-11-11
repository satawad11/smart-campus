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
use App\Services\Camera\Snapshot\FfmpegFrameGrabber;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Add-camera assist: auto site/zone/code defaults, form options, and the
 * pre-save stream preview.
 */
class CameraCreateAssistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
    }

    /** @param  list<string>  $codes */
    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'CAM_'.uniqid(), 'name' => 'Camera Admin', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    private function admin(): User
    {
        return $this->userWith(['camera.view', 'camera.create']);
    }

    public function test_create_without_site_zone_or_code_provisions_defaults(): void
    {
        $this->assertSame(0, Site::count());

        $res = $this->actingAs($this->admin(), 'web')->postJson('/api/v1/cameras', ['name' => 'ประตูหน้า']);

        $res->assertStatus(201);
        $res->assertJsonPath('data.camera_code', 'CAM-0001');
        $this->assertSame(1, Site::count());
        $this->assertSame(1, Zone::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'LOCATION_DEFAULT_PROVISIONED']);

        // Second camera reuses the same site/zone and takes the next code.
        $res2 = $this->actingAs($this->admin(), 'web')->postJson('/api/v1/cameras', ['name' => 'ประตูหลัง']);
        $res2->assertStatus(201);
        $res2->assertJsonPath('data.camera_code', 'CAM-0002');
        $this->assertSame(1, Site::count());
        $this->assertSame(1, Zone::count());
    }

    public function test_create_uses_existing_site_and_zone_when_blank(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();

        $this->actingAs($this->admin(), 'web')
            ->postJson('/api/v1/cameras', ['name' => 'Gate'])
            ->assertStatus(201);

        $camera = Camera::firstOrFail();
        $this->assertSame($site->id, $camera->site_id);
        $this->assertSame($zone->id, $camera->zone_id);
        $this->assertSame(1, Site::count());
    }

    public function test_zone_only_derives_its_site(): void
    {
        Site::factory()->create();
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();

        $this->actingAs($this->admin(), 'web')
            ->postJson('/api/v1/cameras', ['name' => 'Gate', 'zone_uuid' => $zone->uuid])
            ->assertStatus(201);

        $this->assertSame($site->id, Camera::firstOrFail()->site_id);
    }

    public function test_explicit_code_still_honoured_and_malformed_uuid_rejected(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->postJson('/api/v1/cameras', ['name' => 'Gate', 'camera_code' => 'GATE-9'])
            ->assertStatus(201)
            ->assertJsonPath('data.camera_code', 'GATE-9');

        $this->actingAs($this->admin(), 'web')
            ->postJson('/api/v1/cameras', ['name' => 'Gate', 'site_uuid' => 'not-a-uuid'])
            ->assertStatus(422);
    }

    public function test_form_options_lists_sites_zones_and_next_code(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();

        $res = $this->actingAs($this->admin(), 'web')->getJson('/api/v1/cameras/form-options');

        $res->assertOk();
        $res->assertJsonPath('data.sites.0.uuid', $site->uuid);
        $res->assertJsonPath('data.sites.0.zones.0.uuid', $zone->uuid);
        $res->assertJsonPath('data.suggested_camera_code', 'CAM-0001');
        $res->assertJsonPath('data.can_provision_default_location', true);
        $this->assertArrayNotHasKey('id', $res->json('data.sites.0'));
    }

    public function test_new_endpoints_require_camera_create(): void
    {
        $viewer = $this->userWith(['camera.view']);

        $this->actingAs($viewer, 'web')->getJson('/api/v1/cameras/form-options')->assertStatus(403);
        $this->actingAs($viewer, 'web')->postJson('/api/v1/cameras/preview', ['rtsp_url' => 'rtsp://192.0.2.1/s'])->assertStatus(403);
    }

    public function test_preview_returns_frame_and_audits_without_credentials(): void
    {
        config(['camera.snapshot.provider' => 'ffmpeg']);
        Process::fake([
            '*' => Process::result(output: "\xFF\xD8\xFF\xE0fake-jpeg"),
        ]);

        $res = $this->actingAs($this->admin(), 'web')->postJson('/api/v1/cameras/preview', [
            'rtsp_url' => 'rtsp://192.0.2.10:554/stream1',
            'username' => 'operator',
            'password' => 'super-secret',
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.content_type', 'image/jpeg');
        $res->assertJsonPath('data.source', 'CAMERA');

        Process::assertRan(fn ($process) => in_array('rtsp://operator:super-secret@192.0.2.10:554/stream1', $process->command, true));

        $this->assertDatabaseHas('audit_logs', ['action' => 'CAMERA_STREAM_PREVIEWED']);
        $this->assertSame(0, Camera::count());
        $audit = json_encode(AuditLog::where('action', 'CAMERA_STREAM_PREVIEWED')->firstOrFail()->toArray());
        $this->assertStringNotContainsString('super-secret', $audit);
        $this->assertStringNotContainsString('operator', $audit);
    }

    public function test_preview_failure_maps_to_safe_error_code(): void
    {
        config(['camera.snapshot.provider' => 'ffmpeg']);
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'method DESCRIBE failed: 401 Unauthorized', exitCode: 1),
        ]);

        $res = $this->actingAs($this->admin(), 'web')->postJson('/api/v1/cameras/preview', [
            'rtsp_url' => 'rtsp://user:pw@192.0.2.10/stream1',
        ]);

        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'CAMERA_AUTH_FAILED');
        $this->assertStringNotContainsString('pw@', $res->getContent());
    }

    public function test_preview_rejects_non_stream_schemes(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->postJson('/api/v1/cameras/preview', ['rtsp_url' => 'file:///etc/passwd'])
            ->assertStatus(422);
    }

    public function test_stream_url_builder(): void
    {
        $this->assertSame('rtsp://a%40b:p%3Aw@h:554/s', FfmpegFrameGrabber::buildStreamUrl('rtsp://h:554/s', 'a@b', 'p:w'));
        $this->assertSame('rtsp://x:y@h/s', FfmpegFrameGrabber::buildStreamUrl('rtsp://x:y@h/s', 'other', 'pw'));
        $this->assertSame('rtsp://h/s', FfmpegFrameGrabber::buildStreamUrl('rtsp://h/s', null, null));
        $this->assertNull(FfmpegFrameGrabber::buildStreamUrl('  ', 'u', 'p'));
    }
}
