<?php

namespace Tests\Feature;

use App\Http\Resources\LoginHistoryResource;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoginHistoryArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_history_resource_excludes_sensitive_fields_and_internal_ids(): void
    {
        $user = User::factory()->create();
        $log = new LoginLog([
            'user_id' => $user->id,
            'username' => $user->username,
            'success' => true,
            'failure_reason' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'ArchitectureAgent/1.0',
        ]);
        $log->id = 999;
        $log->setRelation('user', $user);

        $resource = new LoginHistoryResource($log);
        $array = $resource->toArray(request());

        $this->assertArrayNotHasKey('id', $array, 'Internal id must not be exposed');
        $this->assertArrayNotHasKey('user_id', $array, 'Internal user_id must not be exposed');
        $this->assertArrayNotHasKey('password', $array, 'Password must never appear in resource');
        $this->assertArrayNotHasKey('session_id', $array, 'Session id must not be exposed');
        $this->assertArrayNotHasKey('remember_token', $array, 'Remember token must not be exposed');

        if (isset($array['user'])) {
            $this->assertArrayNotHasKey('id', $array['user'], 'User object inside resource must not expose internal id');
            $this->assertArrayNotHasKey('password', $array['user'], 'User object must not expose password');
        }
    }

    public function test_no_delete_login_history_routes_exist(): void
    {
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (str_contains($uri, 'login-history')) {
                $this->assertNotContains('DELETE', $route->methods(), "Login history must not have DELETE route: {$uri}");
            }
        }
    }

    public function test_login_logs_table_structure(): void
    {
        $this->assertTrue(Schema::hasTable('login_logs'), 'login_logs table must exist');
        $this->assertTrue(Schema::hasColumn('login_logs', 'username'));
        $this->assertTrue(Schema::hasColumn('login_logs', 'success'));
        $this->assertTrue(Schema::hasColumn('login_logs', 'failure_reason'));
        $this->assertTrue(Schema::hasColumn('login_logs', 'ip_address'));
        $this->assertTrue(Schema::hasColumn('login_logs', 'user_agent'));
    }
}
