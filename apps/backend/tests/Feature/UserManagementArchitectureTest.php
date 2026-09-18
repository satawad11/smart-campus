<?php

namespace Tests\Feature;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AUTH-010 security / architecture guardrails (#56-#64).
 */
class UserManagementArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private function src(string $relative): string
    {
        return file_get_contents(base_path($relative));
    }

    public function test_no_delete_users_route_exists(): void // 56
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'api/v1/users' || $route->uri() === 'api/v1/users/{user}') {
                $this->assertNotContains('DELETE', $route->methods(), "unexpected DELETE on {$route->uri()}");
            }
        }
    }

    public function test_no_role_name_authorization_bypass(): void // 57
    {
        foreach (['app/Services/Auth/PermissionService.php', 'app/Http/Middleware/RequirePermission.php', 'app/Http/Middleware/EnsureUserActive.php'] as $file) {
            $src = $this->src($file);
            $this->assertStringNotContainsString('SUPER_ADMIN', $src, "{$file} must not authorize by role name");
            $this->assertStringNotContainsString('SYSTEM_ADMIN', $src);
        }
    }

    public function test_no_person_site_zone_domain_introduced(): void // 58
    {
        foreach (['Person', 'Site', 'Zone', 'StudentProfile'] as $model) {
            $this->assertFileDoesNotExist(base_path("app/Models/{$model}.php"));
        }
        foreach (glob(base_path('database/migrations/*.php')) as $file) {
            foreach (['create_persons_table', 'create_sites_table', 'create_zones_table', 'create_student'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, basename($file));
            }
        }
    }

    public function test_no_admin_mfa_secret_retrieval(): void // 59
    {
        $controller = $this->src('app/Http/Controllers/Auth/UserController.php');
        $resource = $this->src('app/Http/Resources/UserResource.php');
        $this->assertStringNotContainsString('mfa_secret', $controller);
        $this->assertStringNotContainsString('mfa_secret', $resource);
    }

    public function test_user_resource_excludes_sensitive_fields(): void // 60-63
    {
        $user = User::factory()->create();
        $user->forceFill(['mfa_secret_encrypted' => 'enc', 'remember_token' => 'tok'])->save();

        $array = (new UserResource($user->load('roles')))->toArray(request());

        foreach (['password', 'remember_token', 'mfa_secret_encrypted', 'id', 'failed_login_count', 'locked_until'] as $field) {
            $this->assertArrayNotHasKey($field, $array);
        }
        $this->assertArrayHasKey('uuid', $array);
    }

    public function test_permission_and_active_middleware_attached_to_user_routes(): void // 64
    {
        $expected = [
            'GET|api/v1/users' => 'permission:user.view',
            'POST|api/v1/users' => 'permission:user.create',
            'PUT|api/v1/users/{user}' => 'permission:user.update',
            'PATCH|api/v1/users/{user}/status' => 'permission:user.disable',
            'POST|api/v1/users/{user}/reset-password' => 'permission:user.update',
        ];

        $seen = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $key = $method.'|'.$route->uri();
                if (isset($expected[$key])) {
                    $mw = $route->middleware();
                    $this->assertContains('auth:web', $mw, "{$key} missing auth:web");
                    $this->assertContains('active', $mw, "{$key} missing active");
                    $this->assertContains($expected[$key], $mw, "{$key} missing {$expected[$key]}");
                    $seen[$key] = true;
                }
            }
        }

        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($seen), 'not all user routes were found/verified');
    }
}
