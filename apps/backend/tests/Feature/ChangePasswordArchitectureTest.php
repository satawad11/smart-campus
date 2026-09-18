<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ChangePasswordArchitectureTest extends TestCase
{
    public function test_auth_change_password_route_does_not_require_user_update_permission(): void
    {
        $route = collect(Route::getRoutes())->first(function ($r) {
            return $r->uri() === 'api/v1/auth/change-password' && in_array('POST', $r->methods());
        });

        $this->assertNotNull($route, 'Route api/v1/auth/change-password must exist.');

        $middleware = $route->gatherMiddleware();

        $this->assertContains('auth:web', $middleware);
        $this->assertContains('active', $middleware);
        $this->assertContains('session.active', $middleware);

        foreach ($middleware as $m) {
            $this->assertStringStartsNotWith('permission:', $m, 'Own password change must not require business permissions.');
        }
    }

    public function test_admin_reset_password_route_remains_distinct_and_permission_guarded(): void
    {
        $adminRoute = collect(Route::getRoutes())->first(function ($r) {
            return str_contains($r->uri(), 'users/{user}/reset-password') && in_array('POST', $r->methods());
        });

        $this->assertNotNull($adminRoute, 'Admin reset password route must exist.');
        $middleware = $adminRoute->gatherMiddleware();
        $this->assertContains('permission:user.update', $middleware, 'Admin reset password must require permission:user.update.');
    }
}
