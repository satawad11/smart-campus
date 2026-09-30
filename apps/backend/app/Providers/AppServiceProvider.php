<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('mfa', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // V1 audit A-03: IP-level throttle in addition to per-account lockout
        // (SEC-013 "login failure surge" control). Per account+IP it stays above
        // the lockout threshold (5) so lockout remains the primary control.
        RateLimiter::for('login', function (Request $request) {
            $username = strtolower((string) $request->input('username'));

            return [
                Limit::perMinute((int) config('auth_security.login_throttle_per_account_ip', 10))->by('login:'.$request->ip().'|'.$username),
                Limit::perMinute((int) config('auth_security.login_throttle_per_ip', 30))->by('login-ip:'.$request->ip()),
            ];
        });
    }
}
