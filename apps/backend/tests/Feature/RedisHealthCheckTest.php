<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class RedisHealthCheckTest extends TestCase
{
    public function test_redis_health_reports_ok_when_ping_succeeds(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('ping')->once()->andReturn(true);
        Redis::shouldReceive('connection')->once()->andReturn($connection);

        $this->getJson('/health/redis')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => ['status' => 'ok', 'service' => 'redis'],
            ]);
    }

    public function test_redis_health_reports_down_when_connection_fails(): void
    {
        Redis::shouldReceive('connection')->andThrow(new \RuntimeException('no redis'));

        $this->getJson('/health/redis')
            ->assertStatus(503)
            ->assertJson([
                'success' => false,
                'data' => ['status' => 'down', 'service' => 'redis'],
            ]);
    }
}
