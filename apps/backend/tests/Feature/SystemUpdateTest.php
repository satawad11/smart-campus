<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ACR-OPS-01 web system update: state read from the host agent's files,
 * signed requests dropped in its inbox, SUPER_ADMIN-only, password re-entry,
 * audit, and no secrets in responses.
 */
class SystemUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-agent-key-0123456789abcdef0123456789abcdef';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);

        $this->root = sys_get_temp_dir().'/sc-update-'.Str::random(8);
        File::ensureDirectoryExists($this->root.'/state');
        File::ensureDirectoryExists($this->root.'/requests/inbox');
        File::ensureDirectoryExists($this->root.'/requests/tmp');
        config([
            'system_update.state_dir' => $this->root.'/state',
            'system_update.request_dir' => $this->root.'/requests',
            'system_update.agent_key' => self::KEY,
        ]);

        $this->writeState('installed.json', ['version' => 'v1.1.0-rc6', 'installed_at' => '2026-10-01T00:00:00Z', 'updated_at' => null]);
        $this->writeState('agent.json', ['enabled' => true, 'reason' => null]);
        $this->writeState('history.json', [['at' => '2026-11-01T00:00:00Z', 'action' => 'update', 'from' => 'v1.1.0-rc5', 'to' => 'v1.1.0-rc6']]);
        $this->writeState('releases.json', ['checked_at' => '2026-11-10T00:00:00Z', 'arch' => 'amd64', 'releases' => [
            $this->release('v1.2.0', notes: "## ฟีเจอร์ใหม่\n- หน้าอัปเดตระบบ"),
            $this->release('v1.2.0-rc1', prerelease: true, kit: false),
            $this->release('v1.1.0-rc6', prerelease: true),
            $this->release('v1.1.0-rc5', prerelease: true),
        ]]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function release(string $tag, bool $prerelease = false, bool $kit = true, string $notes = ''): array
    {
        return ['tag' => $tag, 'name' => $tag, 'published_at' => '2026-11-01T00:00:00Z', 'prerelease' => $prerelease, 'has_kit' => $kit, 'notes' => $notes];
    }

    private function writeState(string $name, mixed $data): void
    {
        File::put($this->root.'/state/'.$name, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /** @param  list<string>  $codes */
    private function userWith(array $codes, string $password = 'Correct-Horse-9'): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE, 'username' => 'admin_'.Str::lower(Str::random(5)), 'password' => $password]);
        $role = Role::create(['code' => 'UPD_'.uniqid(), 'name' => 'Update Test', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    /** @return list<array<string, mixed>> */
    private function inbox(): array
    {
        return array_map(fn ($f) => json_decode(File::get($f), true), File::glob($this->root.'/requests/inbox/*.json'));
    }

    public function test_requires_system_update_permission(): void
    {
        $this->getJson('/api/v1/system/update')->assertStatus(401);

        $manager = $this->userWith(['system.view', 'system.manage']);
        $this->actingAs($manager, 'web')->getJson('/api/v1/system/update')->assertStatus(403);
        $this->actingAs($manager, 'web')->postJson('/api/v1/system/update/check')->assertStatus(403);
        $this->actingAs($manager, 'web')->postJson('/api/v1/system/update', ['version' => 'v1.2.0', 'current_password' => 'Correct-Horse-9'])
            ->assertStatus(403);
        $this->assertSame([], $this->inbox());
    }

    public function test_only_super_admin_gets_the_permission_from_the_role_matrix(): void
    {
        $this->seed([RoleSeeder::class, RolePermissionSeeder::class]);

        $this->assertContains('system.update', Role::where('code', 'SUPER_ADMIN')->first()->permissions->pluck('code'));
        $this->assertNotContains('system.update', Role::where('code', 'SYSTEM_ADMIN')->first()->permissions->pluck('code'));
    }

    public function test_state_lists_newer_releases_history_and_no_secrets(): void
    {
        $admin = $this->userWith(['system.update']);
        $res = $this->actingAs($admin, 'web')->getJson('/api/v1/system/update')->assertOk();

        $res->assertJsonPath('data.installed.version', 'v1.1.0-rc6')
            ->assertJsonPath('data.web_update.enabled', true)
            ->assertJsonPath('data.releases.0.tag', 'v1.2.0')
            ->assertJsonPath('data.releases.0.is_newer', true)
            ->assertJsonPath('data.releases.0.notes', "## ฟีเจอร์ใหม่\n- หน้าอัปเดตระบบ")
            ->assertJsonPath('data.releases.2.is_installed', true)
            ->assertJsonPath('data.releases.3.is_newer', false)
            ->assertJsonPath('data.history.0.to', 'v1.1.0-rc6')
            ->assertJsonPath('data.job', null);
        $this->assertStringNotContainsString(self::KEY, $res->getContent());
    }

    public function test_unavailable_when_not_installed_from_kit_or_disabled(): void
    {
        $admin = $this->userWith(['system.update']);

        $this->writeState('agent.json', ['enabled' => false, 'reason' => 'DISABLED_BY_ADMIN']);
        $this->actingAs($admin, 'web')->getJson('/api/v1/system/update')
            ->assertJsonPath('data.web_update', ['enabled' => false, 'reason' => 'DISABLED_BY_ADMIN']);
        $this->actingAs($admin, 'web')->postJson('/api/v1/system/update', ['version' => 'v1.2.0', 'current_password' => 'Correct-Horse-9'])
            ->assertStatus(409)->assertJsonPath('error.code', 'SYSTEM_UPDATE_UNAVAILABLE');

        config(['system_update.state_dir' => $this->root.'/missing']);
        $this->actingAs($admin, 'web')->getJson('/api/v1/system/update')
            ->assertOk()
            ->assertJsonPath('data.installed', null)
            ->assertJsonPath('data.web_update.reason', 'NOT_KIT_INSTALL');
        $this->assertSame([], $this->inbox());
    }

    public function test_update_request_is_signed_like_the_agent_expects_and_audited(): void
    {
        $admin = $this->userWith(['system.update']);
        $this->actingAs($admin, 'web')
            ->postJson('/api/v1/system/update', ['version' => 'v1.2.0', 'current_password' => 'Correct-Horse-9'])
            ->assertStatus(202)
            ->assertJsonPath('data.request.version', 'v1.2.0')
            ->assertJsonPath('data.state.pending.0.type', 'update');

        $inbox = $this->inbox();
        $this->assertCount(1, $inbox);
        $req = $inbox[0];
        $this->assertSame(['id', 'type', 'version', 'requested_by', 'requested_at', 'expires_at', 'signature'], array_keys($req));
        $signed = implode("\n", [$req['id'], 'update', 'v1.2.0', $admin->username, (string) $req['expires_at']]);
        $this->assertSame(hash_hmac('sha256', $signed, self::KEY), $req['signature']);
        $this->assertLessThanOrEqual(time() + 600, $req['expires_at']);
        $this->assertSame([], File::glob($this->root.'/requests/tmp/*'));

        $log = AuditLog::where('action', 'SYSTEM_UPDATE_REQUESTED')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(['version' => 'v1.2.0'], $log->new_values);
        $this->assertStringNotContainsString('Correct-Horse-9', json_encode($log->toArray()));
    }

    public function test_wrong_password_is_rejected_and_audited(): void
    {
        $admin = $this->userWith(['system.update']);
        $this->actingAs($admin, 'web')
            ->postJson('/api/v1/system/update', ['version' => 'v1.2.0', 'current_password' => 'wrong'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['current_password']]]);

        $this->assertSame([], $this->inbox());
        $this->assertSame('WRONG_PASSWORD', AuditLog::where('action', 'SYSTEM_UPDATE_REJECTED')->firstOrFail()->metadata['reason']);
    }

    public function test_validation_and_disallowed_versions(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $admin = $this->userWith(['system.update']);
        $post = fn (array $body) => $this->actingAs($admin, 'web')->postJson('/api/v1/system/update', $body + ['current_password' => 'Correct-Horse-9']);

        $post([])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $post(['version' => 'v1.2.0; reboot'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        foreach (['v1.1.0-rc5', 'v1.1.0-rc6', 'v1.2.0-rc1', 'v9.9.9'] as $tag) {
            $post(['version' => $tag])->assertStatus(422)->assertJsonPath('error.code', 'SYSTEM_UPDATE_VERSION_NOT_ALLOWED');
        }
        $this->assertSame([], $this->inbox());
    }

    public function test_update_requests_are_rate_limited(): void
    {
        $admin = $this->userWith(['system.update']);
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($admin, 'web')->postJson('/api/v1/system/update', ['version' => 'v1.2.0', 'current_password' => 'wrong'])
                ->assertStatus(422);
        }
        $this->actingAs($admin, 'web')->postJson('/api/v1/system/update', ['version' => 'v1.2.0', 'current_password' => 'Correct-Horse-9'])
            ->assertStatus(429);
        $this->assertSame([], $this->inbox());
    }

    public function test_conflict_while_an_update_is_queued_or_running(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $admin = $this->userWith(['system.update']);
        $body = ['version' => 'v1.2.0', 'current_password' => 'Correct-Horse-9'];
        $this->actingAs($admin, 'web')->postJson('/api/v1/system/update', $body)->assertStatus(202);
        $this->actingAs($admin, 'web')->postJson('/api/v1/system/update', $body)
            ->assertStatus(409)->assertJsonPath('error.code', 'SYSTEM_UPDATE_IN_PROGRESS');

        File::cleanDirectory($this->root.'/requests/inbox');
        $this->writeState('status.json', ['state' => 'running', 'type' => 'update', 'version' => 'v1.2.0', 'started_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'message' => 'x']);
        $this->actingAs($admin, 'web')->postJson('/api/v1/system/update', $body)->assertStatus(409);

        // A "running" job older than the stale limit no longer blocks (host rebooted mid-job).
        $this->writeState('status.json', ['state' => 'running', 'type' => 'update', 'version' => 'v1.2.0', 'started_at' => now()->subHours(5)->utc()->format('Y-m-d\TH:i:s\Z'), 'message' => 'x']);
        $this->actingAs($admin, 'web')->getJson('/api/v1/system/update')->assertJsonPath('data.job.stale', true);
        $this->actingAs($admin, 'web')->postJson('/api/v1/system/update', $body)->assertStatus(202);
    }

    public function test_check_request_is_deduplicated_and_audited(): void
    {
        $admin = $this->userWith(['system.update']);
        $first = $this->actingAs($admin, 'web')->postJson('/api/v1/system/update/check')->assertStatus(202)->json('data.request.request_id');
        $second = $this->actingAs($admin, 'web')->postJson('/api/v1/system/update/check')->assertStatus(202)->json('data.request.request_id');

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->inbox());
        $this->assertNull($this->inbox()[0]['version']);
        $this->assertSame(1, AuditLog::where('action', 'SYSTEM_UPDATE_CHECK_REQUESTED')->count());
    }
}
