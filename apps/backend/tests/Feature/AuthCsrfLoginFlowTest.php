<?php

namespace Tests\Feature;

use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Real browser-style SPA session/CSRF login flow (baseline fix, pre-EVT-001
 * cleanup).
 *
 * Deliberately does NOT use actingAs() and does NOT rely on the CSRF
 * middleware's Application::runningUnitTests() bypass (every other test in
 * this suite does, via ValidateCsrfToken::runningUnitTests(), which is why
 * this defect was invisible until now) — each test here forces the app
 * environment to 'local' for the duration of the test so CSRF is genuinely
 * enforced, then restores 'testing' in tearDown so no other test is
 * affected.
 *
 * Cookies captured from a prior response and replayed on the next request
 * use withUnencryptedCookie() rather than withCookie() — the Set-Cookie
 * value is already the fully-encrypted string a real browser would hold
 * and echo back verbatim; TestCase::withCookie() encrypts its argument
 * again before sending, which double-encrypts an already-encrypted value
 * and silently produces an invalid session id (StartSession falls back to
 * generating a brand new one rather than erroring).
 *
 * Exercises exactly the flow the SPA performs: GET the CSRF cookie, echo it
 * back as X-XSRF-TOKEN (exactly as axios does — the value is never
 * decrypted client-side, only by ValidateCsrfToken server-side), POST
 * login, GET /auth/me, POST logout, GET /auth/me again.
 */
class AuthCsrfLoginFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->instance('env', 'local');

        // json()/getJson()/postJson() never actually send any cookie —
        // including one set via withCookie()/withUnencryptedCookie() — for
        // a JSON request unless withCredentials() is enabled
        // (MakesHttpRequests::prepareCookiesForJsonRequest() returns []
        // otherwise). This mirrors axios's own withCredentials:true, which
        // is exactly why services/api.js sets it, so it's the correct
        // default for every request in this test class, not a per-call
        // opt-in.
        $this->withCredentials();

        // phpunit.xml forces SESSION_DRIVER=array for test isolation/speed,
        // but the array driver is explicitly documented to never persist
        // across separate requests — fatal for a test that must carry one
        // session across several simulated HTTP calls. Use the file driver
        // instead (no extra migration needed, unlike 'database').
        config(['session.driver' => 'file']);
    }

    protected function tearDown(): void
    {
        app()->instance('env', 'testing');
        parent::tearDown();
    }

    private function makeUser(): User
    {
        $role = Role::create([
            'code' => 'CSRF_FLOW_ROLE',
            'name' => 'CSRF Flow Test Role',
            'system_role' => true,
            'status' => RoleStatus::ACTIVE,
        ]);

        $user = User::factory()->create([
            'username' => 'csrfflowuser',
            'password' => 'password123',
            'status' => UserStatus::ACTIVE,
            'mfa_enabled' => false,
            'must_change_password' => false,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    /** @return array{0: string, 1: string} [encrypted XSRF-TOKEN cookie value, encrypted laravel-session cookie value] */
    private function bootstrapCsrf(): array
    {
        $res = $this->getJson('/api/v1/auth/csrf-cookie');
        $res->assertOk();

        $xsrf = $this->extractCookieValue($res, 'XSRF-TOKEN');
        $session = $this->extractCookieValue($res, 'laravel-session');

        $this->assertNotNull($xsrf, 'csrf-cookie response must set XSRF-TOKEN');
        $this->assertNotNull($session, 'csrf-cookie response must set laravel-session');

        return [$xsrf, $session];
    }

    /**
     * Parses a cookie's value straight out of the raw Set-Cookie response
     * headers, exactly as a real browser would — Symfony's
     * ResponseHeaderBag::getCookies() only tracks Cookie objects attached
     * in-process via setCookie(), not ones round-tripped through the test
     * client's response, so it comes back empty here even though the
     * header is genuinely present.
     */
    private function extractCookieValue(TestResponse $response, string $name): ?string
    {
        foreach ($response->headers->all()['set-cookie'] ?? [] as $line) {
            if (str_starts_with($line, $name.'=')) {
                $value = substr($line, strlen($name) + 1);
                $value = strstr($value, ';', true) ?: $value;

                return urldecode($value);
            }
        }

        return null;
    }

    public function test_csrf_bootstrap_endpoint_issues_xsrf_token_and_session_cookies(): void
    {
        [$xsrfToken, $sessionCookie] = $this->bootstrapCsrf();

        $this->assertNotEmpty($xsrfToken);
        $this->assertNotEmpty($sessionCookie);
    }

    public function test_login_without_any_csrf_token_is_rejected_with_419(): void
    {
        $this->makeUser();

        $res = $this->postJson('/api/v1/auth/login', [
            'username' => 'csrfflowuser',
            'password' => 'password123',
        ]);

        $res->assertStatus(419);
        $res->assertJsonPath('error.code', 'CSRF_MISMATCH');
        $this->assertGuest('web');
    }

    public function test_login_with_the_session_cookie_but_no_xsrf_header_is_rejected(): void
    {
        $this->makeUser();
        [, $sessionCookie] = $this->bootstrapCsrf();

        $res = $this->withUnencryptedCookie('laravel-session', $sessionCookie)
            ->postJson('/api/v1/auth/login', [
                'username' => 'csrfflowuser',
                'password' => 'password123',
            ]);

        $res->assertStatus(419);
        $this->assertGuest('web');
    }

    public function test_login_with_a_stale_or_forged_xsrf_header_is_rejected(): void
    {
        $this->makeUser();
        [, $sessionCookie] = $this->bootstrapCsrf();

        $res = $this->withUnencryptedCookie('laravel-session', $sessionCookie)
            ->withHeader('X-XSRF-TOKEN', 'not-a-real-token')
            ->postJson('/api/v1/auth/login', [
                'username' => 'csrfflowuser',
                'password' => 'password123',
            ]);

        $res->assertStatus(419);
        $this->assertGuest('web');
    }

    public function test_full_browser_style_login_session_and_logout_flow(): void
    {
        $user = $this->makeUser();

        // 1. SPA requests the CSRF cookie.
        [$xsrfToken, $sessionCookie] = $this->bootstrapCsrf();

        // 2/3. Browser echoes the cookie back as X-XSRF-TOKEN (raw, exactly
        // as axios does — never decrypted client-side) alongside the
        // session cookie established by the same bootstrap call.
        // 4. POST login succeeds.
        $loginRes = $this->withUnencryptedCookie('laravel-session', $sessionCookie)
            ->withUnencryptedCookie('XSRF-TOKEN', $xsrfToken)
            ->withHeader('X-XSRF-TOKEN', $xsrfToken)
            ->postJson('/api/v1/auth/login', [
                'username' => 'csrfflowuser',
                'password' => 'password123',
            ]);

        $loginRes->assertOk();
        $loginRes->assertJsonPath('data.user.username', 'csrfflowuser');
        $this->assertAuthenticatedAs($user, 'web');

        // Login rotates the session id (session fixation protection) — the
        // SPA must continue with whatever cookie the login response set,
        // not the pre-login one.
        $postLoginSession = $this->extractCookieValue($loginRes, 'laravel-session') ?? $sessionCookie;
        $postLoginXsrf = $this->extractCookieValue($loginRes, 'XSRF-TOKEN') ?? $xsrfToken;

        // 5/6. Authenticated session persists: GET current-user succeeds.
        $meRes = $this->withUnencryptedCookie('laravel-session', $postLoginSession)
            ->getJson('/api/v1/auth/me');
        $meRes->assertOk();
        $meRes->assertJsonPath('data.user.username', 'csrfflowuser');

        // 7. Logout (itself a state-changing POST) succeeds using the
        // post-login CSRF token.
        $logoutRes = $this->withUnencryptedCookie('laravel-session', $postLoginSession)
            ->withUnencryptedCookie('XSRF-TOKEN', $postLoginXsrf)
            ->withHeader('X-XSRF-TOKEN', $postLoginXsrf)
            ->postJson('/api/v1/auth/logout');
        $logoutRes->assertOk();

        // 8. Subsequent protected request with the now-invalidated session fails.
        $afterLogoutRes = $this->withUnencryptedCookie('laravel-session', $postLoginSession)
            ->getJson('/api/v1/auth/me');
        $afterLogoutRes->assertStatus(401);
    }
}
