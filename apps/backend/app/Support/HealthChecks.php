<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/**
 * Dependency health probes (FND-010).
 *
 * Each method returns a boolean and swallows exceptions — callers translate
 * that into the standard health envelope. Probes never include credentials,
 * connection strings, or exception detail in their results (no secret leakage).
 */
class HealthChecks
{
    public function database(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function redis(): bool
    {
        try {
            $pong = Redis::connection()->ping();

            return $pong === true || $pong === 1 || $pong === 'PONG' || $pong === '+PONG';
        } catch (\Throwable) {
            return false;
        }
    }

    public function storage(): bool
    {
        try {
            Storage::disk('s3')->exists('.storage-health-probe');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function aiService(): bool
    {
        try {
            $base = rtrim((string) config('services.ai.url'), '/');
            $response = Http::timeout(2)->get($base.'/health');

            return $response->successful() && data_get($response->json(), 'status') === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }
}
