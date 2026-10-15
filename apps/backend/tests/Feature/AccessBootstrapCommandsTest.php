<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Zone;
use App\Services\Auth\PermissionService;
use App\Support\Authorization\ScopeContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * V1 audit A-01/A-02/A-03: the supported bootstrap path for a fresh pilot host
 * (first SUPER_ADMIN, scoped role assignment, site/zone provisioning) and the
 * login throttle.
 */
class AccessBootstrapCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_bootstrap_admin_creates_one_super_admin_with_forced_password_change(): void
    {
        $this->assertSame(0, Artisan::call('access:bootstrap-admin', ['username' => 'pilot.admin']));
        $output = Artisan::output();

        $user = User::where('username', 'pilot.admin')->firstOrFail();
        $this->assertTrue($user->must_change_password);
        $this->assertSame(UserStatus::ACTIVE, $user->status);
        $this->assertTrue(app(PermissionService::class)->can($user, 'role.manage', ScopeContext::global()));

        // The printed one-time password is the real one and is not stored in plain text
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
        $password = end($lines);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'access.bootstrap_admin']);
        // The one-time password never reaches the audit trail (jsonb-safe check)
        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString($password, json_encode($log->getAttributes()));
        }

        // A second run is refused while an active SUPER_ADMIN exists
        $this->assertSame(1, Artisan::call('access:bootstrap-admin', ['username' => 'second.admin']));
        $this->assertDatabaseMissing('users', ['username' => 'second.admin']);
    }

    public function test_provision_location_is_idempotent_and_audited(): void
    {
        $args = ['site_code' => 'PILOT', 'site_name' => 'Pilot Campus', '--zone' => ['GATE-1:Main Gate:GATE']];

        $this->assertSame(0, Artisan::call('locations:provision', $args));
        $this->assertSame(0, Artisan::call('locations:provision', $args));

        $this->assertSame(1, Site::where('code', 'PILOT')->count());
        $this->assertSame(1, Zone::where('code', 'GATE-1')->count());
        $this->assertSame('Asia/Bangkok', Site::where('code', 'PILOT')->value('timezone'));
        $this->assertSame(1, AuditLog::where('action', 'location.site_created')->count());
        $this->assertSame(1, Artisan::call('locations:provision', ['site_code' => 'X', 'site_name' => 'X', '--timezone' => 'Mars/Base']));
    }

    public function test_assign_role_grants_exactly_the_requested_scope(): void
    {
        Artisan::call('locations:provision', ['site_code' => 'A', 'site_name' => 'Site A', '--zone' => ['G1:Gate 1']]);
        Artisan::call('locations:provision', ['site_code' => 'B', 'site_name' => 'Site B', '--zone' => ['G1:Gate 1']]);
        $siteA = Site::where('code', 'A')->first();
        $siteB = Site::where('code', 'B')->first();
        $officer = User::factory()->create(['username' => 'officer.a', 'status' => UserStatus::ACTIVE]);
        $permissions = app(PermissionService::class);

        $this->assertFalse($permissions->can($officer, 'monitor.view', ScopeContext::site($siteA->id)));

        $this->assertSame(0, Artisan::call('access:assign-role', ['username' => 'officer.a', 'role' => 'SECURITY_OFFICER', '--site' => 'A']));
        $this->assertSame(0, Artisan::call('access:assign-role', ['username' => 'officer.a', 'role' => 'SECURITY_OFFICER', '--site' => 'A']));
        $this->assertSame(1, UserRole::where('user_id', $officer->id)->count(), 'assignment is idempotent');

        $this->assertTrue($permissions->can($officer, 'monitor.view', ScopeContext::site($siteA->id)));
        $this->assertFalse($permissions->can($officer, 'monitor.view', ScopeContext::site($siteB->id)), 'no cross-site access');
        $this->assertFalse($permissions->can($officer, 'monitor.view', ScopeContext::global()), 'SITE scope is not GLOBAL');
        $this->assertDatabaseHas('audit_logs', ['action' => 'access.role_assigned']);

        // Site/zone may also be given by UUID (uuid columns are typed on PostgreSQL)
        $this->assertSame(0, Artisan::call('access:assign-role', ['username' => 'officer.a', 'role' => 'SECURITY_OFFICER', '--site' => $siteA->uuid]));
        $this->assertSame(1, UserRole::where('user_id', $officer->id)->count(), 'UUID and code resolve to the same assignment');

        $this->assertSame(0, Artisan::call('access:assign-role', ['username' => 'officer.a', 'role' => 'SECURITY_OFFICER', '--site' => 'A', '--revoke' => true]));
        $this->assertFalse($permissions->can($officer, 'monitor.view', ScopeContext::site($siteA->id)));
        $this->assertDatabaseHas('audit_logs', ['action' => 'access.role_revoked']);
    }

    public function test_assign_role_rejects_unknown_role_site_or_zone(): void
    {
        User::factory()->create(['username' => 'u1']);
        Artisan::call('locations:provision', ['site_code' => 'A', 'site_name' => 'Site A']);

        $this->assertSame(1, Artisan::call('access:assign-role', ['username' => 'u1', 'role' => 'NOPE']));
        $this->assertSame(1, Artisan::call('access:assign-role', ['username' => 'u1', 'role' => 'SECURITY_OFFICER', '--site' => 'ZZ']));
        $this->assertSame(1, Artisan::call('access:assign-role', ['username' => 'u1', 'role' => 'SECURITY_OFFICER', '--zone' => 'G1']));
        $this->assertSame(1, Artisan::call('access:assign-role', ['username' => 'u1', 'role' => 'SECURITY_OFFICER', '--site' => 'A', '--zone' => 'NOPE']));
        $this->assertSame(0, UserRole::count());
    }

    public function test_reset_password_recovers_a_locked_out_admin_from_the_shell(): void
    {
        Artisan::call('access:bootstrap-admin', ['username' => 'pilot.admin']);
        $admin = User::where('username', 'pilot.admin')->firstOrFail();
        $admin->forceFill([
            'must_change_password' => false,
            'failed_login_count' => 5,
            'locked_until' => now()->addHour(),
        ])->save();
        $oldHash = $admin->fresh()->password;

        $this->assertSame(0, Artisan::call('access:reset-password', ['username' => 'pilot.admin', '--yes' => true]));
        $lines = array_values(array_filter(array_map('trim', explode("\n", Artisan::output()))));
        $password = end($lines);

        $admin->refresh();
        $this->assertNotSame($oldHash, $admin->password);
        $this->assertTrue(Hash::check($password, $admin->password));
        $this->assertTrue($admin->must_change_password);
        $this->assertSame(0, $admin->failed_login_count);
        $this->assertNull($admin->locked_until);
        $this->assertDatabaseHas('audit_logs', ['action' => 'USER_PASSWORD_RESET', 'entity_id' => $admin->uuid]);
        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString($password, json_encode($log->getAttributes()));
        }

        // The one-time password logs in (and the account must change it next)
        $this->postJson('/api/v1/auth/login', ['username' => 'pilot.admin', 'password' => $password])
            ->assertOk();
    }

    public function test_reset_password_refuses_unknown_or_inactive_users_and_needs_confirmation(): void
    {
        $this->assertSame(1, Artisan::call('access:reset-password', ['username' => 'nobody', '--yes' => true]));

        $inactive = User::factory()->create(['username' => 'gone', 'status' => UserStatus::DISABLED]);
        $hash = $inactive->password;
        $this->assertSame(1, Artisan::call('access:reset-password', ['username' => 'gone', '--yes' => true]));
        $this->assertSame($hash, $inactive->fresh()->password);

        // Without --yes a non-interactive run is cancelled, not applied
        $active = User::factory()->create(['username' => 'active.user']);
        $hash = $active->password;
        $this->artisan('access:reset-password', ['username' => 'active.user'])
            ->expectsConfirmation('Reset the password of active.user and sign out all of its sessions?', 'no')
            ->assertExitCode(1);
        $this->assertSame($hash, $active->fresh()->password);
    }

    public function test_login_is_throttled_per_ip(): void
    {
        config(['auth_security.login_throttle_per_ip' => 3, 'auth_security.login_throttle_per_account_ip' => 100]);

        foreach (['a', 'b', 'c'] as $name) {
            $this->postJson('/api/v1/auth/login', ['username' => $name, 'password' => 'wrong-password-123'])
                ->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', ['username' => 'd', 'password' => 'wrong-password-123'])
            ->assertStatus(429);
    }
}
