<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EVT-013 — routes/channels.php: who may subscribe to a site's realtime
 * event channel at all (POST /api/v1/broadcasting/auth, registered by
 * ->withBroadcasting() in bootstrap/app.php). Coarse-grained on purpose —
 * "holds monitor.view", not yet "monitor.view for THIS site" — see
 * EVT-014, which narrows this to the subscriber's actual scope.
 *
 * The test env's default broadcaster is null (phpunit.xml), which no-ops
 * auth entirely — these tests switch to the real 'reverb' connection
 * (Pusher-protocol compatible) so routes/channels.php's callback actually
 * runs. Signing is local HMAC (Pusher SDK), so this never touches a real
 * Reverb server or network.
 */
class BroadcastChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        // routes/channels.php already ran once at boot, registering
        // Broadcast::channel() against whatever the default connection was
        // then (phpunit.xml's null driver). Switching config afterwards
        // doesn't move that registration — Broadcast::channel() resolves
        // and registers on the CURRENT default driver instance at the
        // moment it runs, and the manager lazily creates/caches a separate
        // instance per connection name. So the channel is re-required here
        // to register it against the 'reverb' driver instance too, once
        // that becomes the default.
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
    }

    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'EVT_'.uniqid(), 'name' => 'Monitor', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        if ($codes !== []) {
            $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        }
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }

    public function test_requires_authentication(): void
    {
        $site = Site::factory()->create();

        $this->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => "private-site.{$site->uuid}",
            'socket_id' => '1234.5678',
        ])->assertStatus(401);
    }

    public function test_a_user_without_monitor_view_is_denied(): void
    {
        $user = $this->userWith(['event.view']);
        $site = Site::factory()->create();

        $this->actingAs($user, 'web')->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => "private-site.{$site->uuid}",
            'socket_id' => '1234.5678',
        ])->assertStatus(403);
    }

    public function test_a_monitor_view_user_is_authorized(): void
    {
        $user = $this->userWith(['monitor.view']);
        $site = Site::factory()->create();

        $res = $this->actingAs($user, 'web')->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => "private-site.{$site->uuid}",
            'socket_id' => '1234.5678',
        ]);

        $res->assertOk();
        $this->assertNotEmpty($res->json('auth'));
    }
}
