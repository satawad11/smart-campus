<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class StorageHealthCheckTest extends TestCase
{
    public function test_storage_health_reports_ok_when_disk_reachable(): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('s3')->once()->andReturn($disk);

        $this->getJson('/health/storage')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => ['status' => 'ok', 'service' => 'object-storage'],
            ]);
    }

    public function test_storage_health_reports_down_when_disk_unreachable(): void
    {
        Storage::shouldReceive('disk')->with('s3')->andThrow(new \RuntimeException('no storage'));

        $this->getJson('/health/storage')
            ->assertStatus(503)
            ->assertJson([
                'success' => false,
                'data' => ['status' => 'down', 'service' => 'object-storage'],
            ]);
    }
}
