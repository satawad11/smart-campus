<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security baseline: /api/v1 is rate limited per user (per IP when
 * anonymous); the machine /internal/v1 surface is not in that group.
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_requests_beyond_the_limit_get_429(): void
    {
        config(['auth_security.api_rate_limit_per_minute' => 3]);
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user, 'web')->getJson('/api/v1/auth/me')->assertOk();
        }
        $this->actingAs($user, 'web')->getJson('/api/v1/auth/me')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_limits_are_per_user(): void
    {
        config(['auth_security.api_rate_limit_per_minute' => 1]);
        $first = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $second = User::factory()->create(['status' => UserStatus::ACTIVE]);

        $this->actingAs($first, 'web')->getJson('/api/v1/auth/me')->assertOk();
        $this->actingAs($second, 'web')->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_internal_node_api_is_not_api_rate_limited(): void
    {
        config(['auth_security.api_rate_limit_per_minute' => 1]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/internal/v1/ai-nodes/heartbeat')->assertStatus(401); // auth, never 429
        }
    }
}
