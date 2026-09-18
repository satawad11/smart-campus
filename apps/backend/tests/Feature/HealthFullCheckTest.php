<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class HealthFullCheckTest extends TestCase
{
    private function fakeRedisUp(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('ping')->andReturn(true);
        Redis::shouldReceive('connection')->andReturn($connection);
    }

    private function fakeStorageUp(): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->andReturn(false);
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
    }

    public function test_full_health_ok_when_all_dependencies_up(): void
    {
        // database: the test suite uses in-memory sqlite (reachable).
        $this->fakeRedisUp();
        $this->fakeStorageUp();
        Http::fake(['*/health' => Http::response(['status' => 'ok', 'service' => 'ai-service'], 200)]);

        $this->getJson('/health/full')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'ok',
                    'service' => 'backend',
                    'checks' => [
                        'database' => ['status' => 'ok'],
                        'redis' => ['status' => 'ok'],
                        'object_storage' => ['status' => 'ok'],
                        'ai_service' => ['status' => 'ok'],
                    ],
                ],
            ]);
    }

    public function test_full_health_degraded_when_ai_service_down(): void
    {
        $this->fakeRedisUp();
        $this->fakeStorageUp();
        Http::fake(['*/health' => Http::response('', 500)]);

        $this->getJson('/health/full')
            ->assertStatus(503)
            ->assertJson([
                'success' => false,
                'data' => [
                    'status' => 'degraded',
                    'checks' => [
                        'ai_service' => ['status' => 'down'],
                    ],
                ],
            ]);
    }
}
