<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Zone;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * EVT-014 — channel authorization enforces scope (docs/09: "Channel
 * authorization must enforce scope"), narrowing EVT-013's coarse "holds
 * monitor.view at all" check to the subscriber's actual site/zone grant,
 * via the same AUTH-009 scope model every other site-scoped permission in
 * this app already uses.
 */
class BroadcastChannelScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
    }

    private function userScopedTo(?int $siteId, ?int $zoneId): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'EVT_'.uniqid(), 'name' => 'Monitor', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::where('code', 'monitor.view')->value('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'site_id' => $siteId, 'zone_id' => $zoneId]);

        return $user;
    }

    private function auth(User $user, Site $site): TestResponse
    {
        return $this->actingAs($user, 'web')->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => "private-site.{$site->uuid}",
            'socket_id' => '1234.5678',
        ]);
    }

    public function test_a_globally_scoped_grant_covers_every_site(): void
    {
        $user = $this->userScopedTo(null, null); // GLOBAL: no site_id/zone_id on the assignment
        $site = Site::factory()->create();

        $this->auth($user, $site)->assertOk();
    }

    public function test_a_whole_site_grant_covers_that_site(): void
    {
        $site = Site::factory()->create();
        $user = $this->userScopedTo($site->id, null);

        $this->auth($user, $site)->assertOk();
    }

    public function test_a_whole_site_grant_does_not_cover_a_different_site(): void
    {
        $mySite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $user = $this->userScopedTo($mySite->id, null);

        $this->auth($user, $otherSite)->assertStatus(403);
    }

    public function test_a_zone_only_grant_does_not_cover_the_owning_sites_channel(): void
    {
        $site = Site::factory()->create();
        $zone = Zone::factory()->for($site)->create();
        $user = $this->userScopedTo(null, $zone->id);

        // Consistent with EffectiveScope::covers()'s existing SITE-level
        // rule elsewhere in the app: a zone grant never implies the whole
        // site is in scope, even though the zone belongs to that site.
        $this->auth($user, $site)->assertStatus(403);
    }

    public function test_no_monitor_view_grant_at_all_is_denied(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'EVT_'.uniqid(), 'name' => 'NoAccess', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);
        $site = Site::factory()->create();

        $this->auth($user, $site)->assertStatus(403);
    }

    public function test_an_unknown_site_uuid_is_always_denied_even_for_a_global_grant(): void
    {
        $user = $this->userScopedTo(null, null);

        $this->actingAs($user, 'web')->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-site.00000000-0000-0000-0000-000000000000',
            'socket_id' => '1234.5678',
        ])->assertStatus(403);
    }
}
