<?php

namespace Tests\Feature;

use App\Services\Auth\PermissionService;
use App\Support\Authorization\ScopeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AUTH-009 architecture guardrails (#27, #28 + required static checks).
 */
class ScopeArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private function backend(string $relative): string
    {
        return base_path($relative);
    }

    // 27 (superseded by CAM-001): Site/Zone are now a legitimate Module 04
    // domain, so the original "table does not exist" guard no longer applies.
    // The invariant that mattered — AUTH-009's scope authorization stays a
    // numeric-id abstraction and never reaches into Site/Zone internals — is
    // still checked directly below.
    public function test_scope_authorization_does_not_couple_to_site_or_zone_models(): void
    {
        foreach ([
            'app/Services/Auth/PermissionService.php',
            'app/Support/Authorization/ScopeContext.php',
            'app/Support/Authorization/EffectiveScope.php',
        ] as $file) {
            $source = file_get_contents($this->backend($file));
            $this->assertStringNotContainsString('App\Models\Site', $source, "{$file} must not couple to the Site model");
            $this->assertStringNotContainsString('App\Models\Zone', $source, "{$file} must not couple to the Zone model");
            $this->assertStringNotContainsString('Site::', $source, "{$file} must not query the Site model");
            $this->assertStringNotContainsString('Zone::', $source, "{$file} must not query the Zone model");
        }
    }

    public function test_no_role_name_scope_bypass_in_permission_service(): void
    {
        $source = file_get_contents($this->backend('app/Services/Auth/PermissionService.php'));

        // No hardcoded role-name global bypass for SUPER_ADMIN or SYSTEM_ADMIN.
        $this->assertStringNotContainsString('SUPER_ADMIN', $source);
        $this->assertStringNotContainsString('SYSTEM_ADMIN', $source);
    }

    // No scoped columns were silently added to user_permissions.
    public function test_user_permissions_has_no_scope_columns(): void
    {
        $this->assertTrue(Schema::hasTable('user_permissions'));
        $this->assertFalse(Schema::hasColumn('user_permissions', 'site_id'));
        $this->assertFalse(Schema::hasColumn('user_permissions', 'zone_id'));
    }

    // 28: public API routes do not accept internal numeric site_id/zone_id.
    public function test_public_api_routes_do_not_expose_numeric_scope_ids(): void
    {
        $api = file_get_contents($this->backend('routes/api.php'));

        $this->assertStringNotContainsString('{site_id}', $api);
        $this->assertStringNotContainsString('{zone_id}', $api);
        $this->assertStringNotContainsString('{site}', $api);
        $this->assertStringNotContainsString('{zone}', $api);
    }

    // Scope authorization is only reachable through an explicit ScopeContext,
    // never a raw request array — the server controls scope evaluation.
    public function test_can_requires_explicit_scope_context(): void
    {
        $method = new ReflectionMethod(PermissionService::class, 'can');
        $params = $method->getParameters();

        $this->assertCount(3, $params);
        $this->assertSame(ScopeContext::class, $params[2]->getType()?->getName());
    }
}
