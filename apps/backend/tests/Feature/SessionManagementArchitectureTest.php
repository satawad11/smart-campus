<?php

namespace Tests\Feature;

use App\Http\Resources\SessionResource;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SessionManagementArchitectureTest extends TestCase
{
    public function test_session_resource_cannot_serialize_framework_session_id_or_internal_ids(): void
    {
        $session = (new UserSession)->forceFill([
            'id' => 9999,
            'user_id' => 8888,
            'uuid' => '00000000-0000-0000-0000-000000000001',
            'session_id' => 'raw_laravel_session_id_must_never_leak',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Architecture Test Agent',
            'device_name' => 'TestDevice',
            'login_at' => now(),
            'last_activity_at' => now(),
        ]);

        $resource = new SessionResource($session);
        $serialized = $resource->toArray(Request::create('/api/v1/auth/sessions', 'GET'));

        $this->assertArrayNotHasKey('id', $serialized, 'SessionResource must never leak internal id');
        $this->assertArrayNotHasKey('user_id', $serialized, 'SessionResource must never leak user_id');
        $this->assertArrayNotHasKey('session_id', $serialized, 'SessionResource must never leak framework session_id');
        $this->assertArrayNotHasKey('remember_token', $serialized, 'SessionResource must never leak remember tokens');
        $this->assertArrayHasKey('uuid', $serialized);
        $this->assertSame('00000000-0000-0000-0000-000000000001', $serialized['uuid']);
    }

    public function test_no_route_parameter_is_named_raw_session_id(): void
    {
        $routes = Route::getRoutes();

        foreach ($routes as $route) {
            $uri = $route->uri();
            $this->assertStringNotContainsString(
                '{session_id}',
                $uri,
                "Route URI '{$uri}' must not use raw session_id as parameter name."
            );
        }
    }

    public function test_no_delete_operations_on_user_sessions_table_in_app(): void
    {
        $appPath = app_path();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appPath));

        foreach ($files as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            // Ensure no hard delete statements on user_sessions in application services/controllers
            $this->assertDoesNotMatchRegularExpression(
                '/DB::table\([\'"]user_sessions[\'"]\)->(?:.*)->delete\(\)/i',
                $content,
                "File {$file->getPathname()} must not perform hard-deletes on user_sessions table."
            );
            $this->assertDoesNotMatchRegularExpression(
                '/UserSession::(?:.*)->delete\(\)/i',
                $content,
                "File {$file->getPathname()} must not perform hard-deletes on UserSession model."
            );
        }
    }

    public function test_no_production_bypass_in_ensure_session_active(): void
    {
        $middlewareFile = app_path('Http/Middleware/EnsureSessionActive.php');
        $content = file_get_contents($middlewareFile);

        $this->assertStringNotContainsString(
            'app()->environment(\'testing\')',
            $content,
            'EnsureSessionActive must not contain environment bypasses'
        );
        $this->assertStringNotContainsString(
            'config(\'app.env\')',
            $content,
            'EnsureSessionActive must not contain config app.env bypasses'
        );
    }
}
