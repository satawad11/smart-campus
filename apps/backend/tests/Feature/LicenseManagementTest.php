<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Exceptions\LicenseException;
use App\Models\AuditLog;
use App\Models\Camera;
use App\Models\License;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SystemAlert;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Zone;
use App\Services\Camera\CameraService;
use App\Services\License\LicenseService;
use App\Services\License\LicenseState;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\LicenseSigner;
use Tests\TestCase;

/**
 * COM-02 commercial license: offline signed verification, lifecycle,
 * tamper/replay/clock resistance, revocation, growth-only enforcement,
 * API authorization and expiry alerts.
 */
class LicenseManagementTest extends TestCase
{
    use RefreshDatabase;

    private LicenseSigner $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('ext-sodium is required (present in the backend image and CI).');
        }
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);

        $this->vendor = new LicenseSigner;
        config([
            'license.trusted_keys' => [$this->vendor->keyId => $this->vendor->publicKey],
            'license.enforcement' => 'enforce',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): LicenseService
    {
        return app(LicenseService::class);
    }

    private function installationId(): string
    {
        return $this->service()->installationId();
    }

    /** @param  array<string, mixed>  $overrides */
    private function install(array $overrides = []): License
    {
        return $this->service()->install($this->vendor->license($this->installationId(), $overrides), null);
    }

    private function expectRejection(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected license rejection {$code}");
        } catch (LicenseException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    /** @param  list<string>  $codes */
    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'LIC_'.uniqid(), 'name' => 'License Test', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    /** Creates an ACTIVE camera regardless of license (enforcement off). */
    private function seedCamera(): Camera
    {
        $mode = config('license.enforcement');
        config(['license.enforcement' => 'off']);
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();
        $camera = app(CameraService::class)->createCamera([
            'site_uuid' => $site->uuid, 'zone_uuid' => $zone->uuid,
            'camera_code' => 'CAM_'.uniqid(), 'name' => 'Gate',
        ], null, null);
        config(['license.enforcement' => $mode]);

        return $camera;
    }

    /** @return array<string, mixed> */
    private function cameraPayload(): array
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();

        return ['site_uuid' => $site->uuid, 'zone_uuid' => $zone->uuid, 'camera_code' => 'CAM_'.uniqid(), 'name' => 'Gate'];
    }

    // ---------------- identity and verification ----------------

    public function test_installation_id_is_created_once_and_stable(): void
    {
        $first = $this->installationId();

        $this->assertTrue(Str::isUuid($first));
        $this->assertSame($first, $this->installationId());
        $this->assertDatabaseCount('installation_identities', 1);
    }

    public function test_no_license_reports_none(): void
    {
        $state = $this->service()->state();

        $this->assertSame(LicenseState::NONE, $state->state);
        $this->assertFalse($state->usable());
    }

    public function test_valid_license_installs_and_is_audited(): void
    {
        $license = $this->install(['limits' => ['max_cameras' => 4, 'max_ai_nodes' => 2]]);

        $state = $this->service()->state();
        $this->assertSame(LicenseState::VALID, $state->state);
        $this->assertSame(4, $state->limit('max_cameras'));
        $this->assertTrue($state->hasFeature('face'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'LICENSE_INSTALLED', 'entity_id' => $license->uuid]);
    }

    public function test_tampered_payload_is_rejected_and_audited(): void
    {
        $envelope = json_decode($this->vendor->license($this->installationId()), true);
        $payload = json_decode(base64_decode(strtr($envelope['payload'], '-_', '+/')), true);
        $payload['limits']['max_cameras'] = 999;
        $envelope['payload'] = LicenseSigner::b64url(json_encode($payload));

        $this->expectRejection('LICENSE_SIGNATURE_INVALID', fn () => $this->service()->install(json_encode($envelope), null));
        $this->assertDatabaseHas('audit_logs', ['action' => 'LICENSE_REJECTED']);
        $this->assertDatabaseCount('licenses', 0);
    }

    public function test_license_from_an_untrusted_key_is_rejected(): void
    {
        $other = new LicenseSigner('other-key');

        $this->expectRejection('LICENSE_UNTRUSTED_KEY', fn () => $this->service()->install($other->license($this->installationId()), null));
    }

    public function test_same_key_id_with_a_different_key_is_rejected(): void
    {
        $impostor = new LicenseSigner($this->vendor->keyId);

        $this->expectRejection('LICENSE_SIGNATURE_INVALID', fn () => $this->service()->install($impostor->license($this->installationId()), null));
    }

    public function test_license_for_another_installation_is_rejected(): void
    {
        $this->expectRejection('LICENSE_INSTALLATION_MISMATCH',
            fn () => $this->service()->install($this->vendor->license((string) Str::uuid()), null));
    }

    public function test_revocation_list_is_never_accepted_as_a_license_and_vice_versa(): void
    {
        $this->expectRejection('LICENSE_WRONG_TYPE', fn () => $this->service()->install($this->vendor->revocationList(1, []), null));
        $this->expectRejection('LICENSE_WRONG_TYPE',
            fn () => $this->service()->importRevocationList($this->vendor->license($this->installationId()), null));
    }

    public function test_malformed_documents_are_rejected(): void
    {
        foreach (['', 'not json', '{"format":"other"}', json_encode(['format' => 'smart-campus-signed/v1', 'key_id' => 'test-k1', 'payload' => '***', 'signature' => 'x'])] as $doc) {
            $this->expectRejection('LICENSE_MALFORMED', fn () => $this->service()->install($doc, null));
        }
        $this->expectRejection('LICENSE_MALFORMED', fn () => $this->install(['grace_days' => 365]));
        $this->expectRejection('LICENSE_MALFORMED', fn () => $this->install(['limits' => ['max_cameras' => -1]]));
    }

    public function test_already_expired_license_cannot_be_installed(): void
    {
        $this->expectRejection('LICENSE_EXPIRED', fn () => $this->install([
            'not_before' => now()->subYears(2)->toIso8601String(),
            'expires_at' => now()->subYear()->toIso8601String(),
            'grace_days' => 0,
        ]));
    }

    public function test_renewal_supersedes_and_an_older_license_cannot_be_replayed(): void
    {
        $older = $this->vendor->license($this->installationId(), ['issued_at' => now()->subDays(30)->toIso8601String(), 'limits' => ['max_cameras' => 50, 'max_ai_nodes' => 10]]);
        $this->install(['issued_at' => now()->subDays(40)->toIso8601String()]);
        $this->service()->install($older, null);
        $this->install(['issued_at' => now()->subDay()->toIso8601String(), 'limits' => ['max_cameras' => 2, 'max_ai_nodes' => 1]]);

        $this->assertSame(1, License::where('status', License::STATUS_ACTIVE)->count());
        $this->assertSame(2, License::where('status', License::STATUS_SUPERSEDED)->count());
        $this->expectRejection('LICENSE_OLDER_THAN_ACTIVE', fn () => $this->service()->install($older, null));
        $this->assertSame(2, $this->service()->state()->limit('max_cameras'));
    }

    // ---------------- lifecycle ----------------

    public function test_expiry_lifecycle_valid_expiring_grace_expired(): void
    {
        $expires = now()->addDays(60)->startOfSecond();
        $this->install(['expires_at' => $expires->toIso8601String(), 'grace_days' => 10]);

        $this->assertSame(LicenseState::VALID, $this->service()->state()->state);

        Carbon::setTestNow($expires->copy()->subDays(10));
        $this->assertSame(LicenseState::EXPIRING, $this->service()->state()->state);

        Carbon::setTestNow($expires->copy()->addDays(5));
        $state = $this->service()->state();
        $this->assertSame(LicenseState::GRACE, $state->state);
        $this->assertTrue($state->usable());

        Carbon::setTestNow($expires->copy()->addDays(11));
        $state = $this->service()->state();
        $this->assertSame(LicenseState::EXPIRED, $state->state);
        $this->assertFalse($state->usable());
    }

    public function test_not_yet_valid_license(): void
    {
        $this->install(['not_before' => now()->addDays(3)->toIso8601String()]);

        $this->assertSame(LicenseState::NOT_YET_VALID, $this->service()->state()->state);
    }

    public function test_database_tamper_of_limits_and_expiry_has_no_effect(): void
    {
        $expires = now()->addDays(5);
        $license = $this->install(['expires_at' => $expires->toIso8601String(), 'grace_days' => 0]);
        License::whereKey($license->id)->update(['expires_at' => now()->addYears(10), 'limits' => json_encode(['max_cameras' => 999, 'max_ai_nodes' => 999])]);

        Carbon::setTestNow($expires->copy()->addDay());
        $state = $this->service()->state();

        $this->assertSame(LicenseState::EXPIRED, $state->state);
        $this->assertSame(2, $state->limit('max_cameras'));
    }

    public function test_tampered_stored_envelope_reads_invalid(): void
    {
        $license = $this->install();
        $envelope = json_decode($license->envelope, true);
        $envelope['signature'] = LicenseSigner::b64url(str_repeat("\0", 64));
        License::whereKey($license->id)->update(['envelope' => json_encode($envelope)]);

        $state = $this->service()->state();
        $this->assertSame(LicenseState::INVALID, $state->state);
        $this->assertSame('LICENSE_SIGNATURE_INVALID', $state->reasonCode);
    }

    public function test_removing_the_trusted_key_invalidates_the_installed_license(): void
    {
        $this->install();
        config(['license.trusted_keys' => []]);

        $this->assertSame(LicenseState::INVALID, $this->service()->state()->state);
    }

    public function test_clock_rollback_cannot_extend_a_license(): void
    {
        $expires = now()->addDays(40)->startOfSecond();
        $this->install(['expires_at' => $expires->toIso8601String(), 'grace_days' => 0]);

        // The scheduler validated after expiry; then the clock is set back.
        Carbon::setTestNow($expires->copy()->addDays(2));
        $this->service()->recordValidation();
        Carbon::setTestNow($expires->copy()->subDays(20));

        $state = $this->service()->state();
        $this->assertSame(LicenseState::EXPIRED, $state->state);
        $this->assertTrue($state->clockRollbackDetected);
    }

    // ---------------- revocation ----------------

    public function test_revocation_list_revokes_installed_license_and_blocks_reinstall(): void
    {
        $license = $this->install();

        $added = $this->service()->importRevocationList($this->vendor->revocationList(1, [$license->license_id]), null);

        $this->assertSame(1, $added);
        $this->assertSame(LicenseState::REVOKED, $this->service()->state()->state);
        $this->assertDatabaseHas('audit_logs', ['action' => 'LICENSE_REVOCATIONS_IMPORTED']);
        $this->expectRejection('LICENSE_REVOKED', fn () => $this->install(['license_id' => $license->license_id]));
    }

    public function test_stale_or_replayed_revocation_list_is_rejected(): void
    {
        $this->service()->importRevocationList($this->vendor->revocationList(5, []), null);

        $this->expectRejection('REVOCATION_LIST_STALE', fn () => $this->service()->importRevocationList($this->vendor->revocationList(5, []), null));
        $this->expectRejection('REVOCATION_LIST_STALE', fn () => $this->service()->importRevocationList($this->vendor->revocationList(4, []), null));
        $this->assertSame(0, $this->service()->importRevocationList($this->vendor->revocationList(6, []), null));
    }

    // ---------------- enforcement ----------------

    public function test_enforce_mode_refuses_cameras_beyond_the_limit(): void
    {
        $this->install(['limits' => ['max_cameras' => 1, 'max_ai_nodes' => 1]]);
        $this->seedCamera();
        $admin = $this->userWith(['camera.create', 'camera.view']);

        $res = $this->actingAs($admin, 'web')->postJson('/api/v1/cameras', $this->cameraPayload());

        $res->assertStatus(409)->assertJsonPath('error.code', 'LICENSE_LIMIT_REACHED');
        $this->assertSame(1, Camera::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'LICENSE_ENFORCEMENT_BLOCKED']);
    }

    public function test_enforce_mode_allows_cameras_within_the_limit(): void
    {
        $this->install(['limits' => ['max_cameras' => 2, 'max_ai_nodes' => 1]]);
        $this->seedCamera();
        $admin = $this->userWith(['camera.create']);

        $this->actingAs($admin, 'web')->postJson('/api/v1/cameras', $this->cameraPayload())->assertStatus(201);
    }

    public function test_inactive_cameras_do_not_count_but_reactivation_is_checked(): void
    {
        $this->install(['limits' => ['max_cameras' => 1, 'max_ai_nodes' => 1]]);
        $first = $this->seedCamera();
        $second = $this->seedCamera();
        Camera::whereKey($second->id)->update(['status' => DeviceStatus::INACTIVE]);
        $admin = $this->userWith(['camera.enable']);

        $this->actingAs($admin, 'web')->patchJson("/api/v1/cameras/{$second->uuid}/status", ['status' => 'ACTIVE'])
            ->assertStatus(409)->assertJsonPath('error.code', 'LICENSE_LIMIT_REACHED');

        $this->actingAs($admin, 'web')->patchJson("/api/v1/cameras/{$first->uuid}/status", ['status' => 'INACTIVE'])->assertOk();
        $this->actingAs($admin, 'web')->patchJson("/api/v1/cameras/{$second->uuid}/status", ['status' => 'ACTIVE'])->assertOk();
    }

    public function test_enforce_mode_refuses_ai_nodes_beyond_the_limit(): void
    {
        $this->install(['limits' => ['max_cameras' => 5, 'max_ai_nodes' => 0]]);
        $admin = $this->userWith(['ai_node.manage']);

        $this->actingAs($admin, 'web')->postJson('/api/v1/ai-nodes', ['node_code' => 'NODE_'.uniqid(), 'name' => 'Edge 1'])
            ->assertStatus(409)->assertJsonPath('error.code', 'LICENSE_LIMIT_REACHED');
    }

    public function test_enforce_mode_without_a_license_refuses_growth(): void
    {
        $admin = $this->userWith(['camera.create']);

        $this->actingAs($admin, 'web')->postJson('/api/v1/cameras', $this->cameraPayload())
            ->assertStatus(409)->assertJsonPath('error.code', 'LICENSE_REQUIRED');
    }

    public function test_face_enrollment_requires_the_face_feature(): void
    {
        $this->install(['features' => ['monitor', 'report']]);

        try {
            $this->service()->assertCanGrow(LicenseService::RESOURCE_FACE_ENROLLMENT);
            $this->fail('Expected LICENSE_FEATURE_NOT_ENTITLED');
        } catch (ApiException $e) {
            $this->assertSame('LICENSE_FEATURE_NOT_ENTITLED', $e->errorCode);
        }
    }

    public function test_warn_and_off_modes_never_block(): void
    {
        $admin = $this->userWith(['camera.create']);

        foreach (['warn', 'off', 'typo-falls-back-to-warn'] as $mode) {
            config(['license.enforcement' => $mode]);
            $this->actingAs($admin, 'web')->postJson('/api/v1/cameras', $this->cameraPayload())->assertStatus(201);
        }
        $this->assertSame(0, AuditLog::where('action', 'LICENSE_ENFORCEMENT_BLOCKED')->count());
    }

    public function test_expired_license_never_blocks_reading_existing_data(): void
    {
        $camera = $this->seedCamera();
        $this->install(['expires_at' => now()->addDay()->toIso8601String(), 'grace_days' => 0]);
        Carbon::setTestNow(now()->addDays(3));
        $this->assertSame(LicenseState::EXPIRED, $this->service()->state()->state);

        $viewer = $this->userWith(['camera.view']);
        $this->actingAs($viewer, 'web')->getJson('/api/v1/cameras')->assertOk();
        $this->actingAs($viewer, 'web')->getJson("/api/v1/cameras/{$camera->uuid}")->assertOk();
        $this->assertSame(1, Camera::count());
    }

    // ---------------- API ----------------

    public function test_license_api_requires_authentication_and_permissions(): void
    {
        $this->getJson('/api/v1/system/license')->assertStatus(401);

        $nobody = $this->userWith(['camera.view']);
        $this->actingAs($nobody, 'web')->getJson('/api/v1/system/license')->assertStatus(403);

        $viewer = $this->userWith(['system.view']);
        $this->actingAs($viewer, 'web')->getJson('/api/v1/system/license')->assertOk();
        $this->actingAs($viewer, 'web')->postJson('/api/v1/system/license', ['license' => 'x'])->assertStatus(403);
        $this->actingAs($viewer, 'web')->postJson('/api/v1/system/license/revocations', ['revocation_list' => 'x'])->assertStatus(403);
    }

    public function test_license_api_installs_and_never_returns_the_envelope(): void
    {
        $manager = $this->userWith(['system.view', 'system.manage']);
        $installationId = $this->actingAs($manager, 'web')->getJson('/api/v1/system/license')
            ->assertOk()->assertJsonPath('data.state', 'NONE')->json('data.installation_id');

        $res = $this->actingAs($manager, 'web')->postJson('/api/v1/system/license', ['license' => $this->vendor->license($installationId)]);

        $res->assertStatus(201)->assertJsonPath('data.state', 'VALID')->assertJsonPath('data.license.limits.max_cameras', 2);
        $this->assertStringNotContainsString('signature', $res->getContent());
        $this->assertStringNotContainsString('envelope', $res->getContent());
    }

    public function test_license_api_rejects_with_standard_error_envelope(): void
    {
        $manager = $this->userWith(['system.manage']);

        $this->actingAs($manager, 'web')->postJson('/api/v1/system/license', ['license' => 'garbage'])
            ->assertStatus(422)->assertJsonPath('success', false)->assertJsonPath('error.code', 'LICENSE_MALFORMED');
        $this->actingAs($manager, 'web')->postJson('/api/v1/system/license', [])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    // ---------------- alerts ----------------

    public function test_license_check_raises_one_expiring_alert(): void
    {
        $this->install(['expires_at' => now()->addDays(10)->toIso8601String()]);

        $this->artisan('license:check')->assertSuccessful();
        $this->artisan('license:check')->assertSuccessful();

        $this->assertSame(1, SystemAlert::where('type', 'LICENSE_EXPIRING')->count());
    }

    public function test_license_check_alerts_when_active_resources_exceed_the_limit(): void
    {
        $this->seedCamera();
        $this->seedCamera();
        $this->install(['limits' => ['max_cameras' => 1, 'max_ai_nodes' => 1]]);

        $this->artisan('license:check')->assertSuccessful();

        $this->assertSame(1, SystemAlert::where('type', 'LICENSE_LIMIT_EXCEEDED')->count());
    }

    public function test_license_check_in_warn_mode_without_license_raises_nothing(): void
    {
        config(['license.enforcement' => 'warn']);

        $this->artisan('license:check')->assertSuccessful();
        $this->assertSame(0, SystemAlert::count());

        config(['license.enforcement' => 'enforce']);
        $this->artisan('license:check')->assertSuccessful();
        $this->assertSame(1, SystemAlert::where('type', 'LICENSE_MISSING')->count());
    }

    public function test_license_status_command_prints_installation_id(): void
    {
        $id = $this->installationId();

        $this->artisan('license:status')->expectsOutputToContain($id)->assertSuccessful();
    }
}
