<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-003: Production Security Headers & CORS Tests.
 */
class SecurityHeadersCorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_include_hardened_security_headers(): void
    {
        $res = $this->getJson('/api/v1/features');

        $res->assertOk();
        $res->assertHeader('X-Frame-Options', 'DENY');
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-XSS-Protection', '0');
        $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $res->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');

        $csp = $res->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_https_requests_include_hsts_header(): void
    {
        $res = $this->withHeaders(['X-Forwarded-Proto' => 'https'])->getJson('/api/v1/features');

        $res->assertOk();
        $hsts = $res->headers->get('Strict-Transport-Security');
        $this->assertNotNull($hsts);
        $this->assertStringContainsString('max-age=31536000', $hsts);
        $this->assertStringContainsString('includeSubDomains', $hsts);
    }

    public function test_cors_policy_rejects_unapproved_origins_and_does_not_wildcard_credentials(): void
    {
        $corsConfig = config('cors');

        // When credentials are supported, allowed_origins MUST NOT be wildcard '*'
        $this->assertTrue($corsConfig['supports_credentials']);
        $this->assertNotContains('*', $corsConfig['allowed_origins']);

        // Request from unapproved origin must not receive Access-Control-Allow-Origin
        $res = $this->withHeaders([
            'Origin' => 'https://malicious-attacker-site.com',
        ])->getJson('/api/v1/features');

        $this->assertNull($res->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_cors_policy_allows_approved_origin(): void
    {
        $res = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
        ])->getJson('/api/v1/features');

        $res->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        $res->assertHeader('Access-Control-Allow-Credentials', 'true');
    }
}
