<?php

namespace Tests;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Deterministic tracked session id for the web-guard user under test.
     * This adapts the TEST harness only; production enforcement
     * (EnsureSessionActive) is unchanged and fail-closed.
     */
    protected ?string $trackedWebSessionId = null;

    /**
     * Authenticate as a web-guard user AND provision the matching tracked
     * user_sessions row, pinning the session id so it survives the test
     * client's per-request session rotation. Protected-route tests rely on this:
     * production has no equivalent bypass — a real login/MFA-verify creates the
     * tracked row, and EnsureSessionActive fails closed without one.
     */
    public function authenticateWithTrackedSession(User $user): string
    {
        parent::actingAs($user, 'web');

        return $this->provisionTrackedSession($user);
    }

    /**
     * Retrofit: acting as a web user in a test implies a tracked session, so the
     * fail-closed EnsureSessionActive middleware finds a matching record. Other
     * guards are untouched.
     */
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        parent::actingAs($user, $guard);

        if (($guard === null || $guard === 'web') && $user instanceof User) {
            $this->provisionTrackedSession($user);
        }

        return $this;
    }

    /**
     * Inject the pinned tracked session cookie into every request so the server
     * reuses one stable session id (the test client otherwise rotates it).
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if ($this->trackedWebSessionId !== null) {
            $cookieName = config('session.cookie');
            if (! array_key_exists($cookieName, $cookies)) {
                $cookies[$cookieName] = $this->trackedWebSessionId;
            }
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function provisionTrackedSession(User $user): string
    {
        // Cookies must be readable as the raw session id to pin it in tests.
        $this->withoutMiddleware(EncryptCookies::class);

        // Pin the test-context session's own id so session() manipulations in a
        // test still reach the (cookie-pinned) request session.
        $store = $this->app['session'];
        if ($this->trackedWebSessionId !== null) {
            $store->setId($this->trackedWebSessionId);
        } elseif (! $store->isStarted()) {
            $store->start();
        }

        $sessionId = $store->getId();
        $this->trackedWebSessionId = $sessionId;

        UserSession::query()->updateOrCreate(
            ['user_id' => $user->getAuthIdentifier(), 'session_id' => $sessionId],
            [
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'login_at' => now(),
                'last_activity_at' => now(),
                'revoked_at' => null,
                'logout_at' => null,
            ],
        );

        return $sessionId;
    }
}
