<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_returns_ok_envelope(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Success',
                'data' => [
                    'status' => 'ok',
                    'service' => 'backend',
                ],
                'meta' => null,
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['status', 'service', 'time'],
                'meta',
            ]);
    }
}
