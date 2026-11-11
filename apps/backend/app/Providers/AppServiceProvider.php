<?php

namespace App\Providers;

use App\Services\Security\ClamAvUploadScanner;
use App\Services\Security\DisabledUploadScanner;
use App\Services\Security\UploadScanner;
use Aws\CommandInterface;
use Aws\Middleware;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    private const OBJECT_WRITE_COMMANDS = ['PutObject', 'CopyObject', 'CreateMultipartUpload'];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(UploadScanner::class, function () {
            if (config('upload_scan.driver') === 'clamav') {
                return new ClamAvUploadScanner(
                    (string) config('upload_scan.clamav.host'),
                    (int) config('upload_scan.clamav.port'),
                    (float) config('upload_scan.clamav.timeout_seconds'),
                );
            }

            return new DisabledUploadScanner;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Object ACLs are never sent: every object stays private to its bucket.
        // Flysystem adds "x-amz-acl" to each upload, which the least-privilege
        // app identity may not set; granting it that right (WriteAcp) would let
        // a "public-read" ACL expose files anonymously on SeaweedFS.
        Storage::extend('s3', function ($app, array $config) {
            $disk = $app->make('filesystem')->createS3Driver($config);
            $disk->getClient()->getHandlerList()->appendInit(
                Middleware::mapCommand(function (CommandInterface $command) {
                    if (in_array($command->getName(), self::OBJECT_WRITE_COMMANDS, true)) {
                        unset($command['ACL']);
                    }

                    return $command;
                }),
                'strip-object-acl',
            );

            return $disk;
        });

        RateLimiter::for('api', function (Request $request) {
            $perMinute = (int) config('auth_security.api_rate_limit_per_minute', 300);

            return Limit::perMinute(max(1, $perMinute))->by('api:'.($request->user()?->id ?: $request->ip()));
        });

        // COM-03e: the unauthenticated enrollment-code exchange, per IP.
        RateLimiter::for('ai-node-enroll', function (Request $request) {
            return Limit::perMinute(max(1, (int) config('ai_node.enrollment.attempts_per_minute', 10)))
                ->by('ai-node-enroll:'.$request->ip());
        });

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
