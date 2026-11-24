<?php

namespace Database\Factories;

use App\Enums\AccessDecisionOutcome;
use App\Models\AccessPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccessPolicy>
 */
class AccessPolicyFactory extends Factory
{
    protected $model = AccessPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Test Policy '.Str::random(6),
            'subject_type' => 'STUDENT',
            'subject_status' => 'ACTIVE',
            'site_id' => null,
            'zone_id' => null,
            'event_type' => null,
            'gate_id' => null,
            'gate_mode' => null,
            'match_result' => null,
            'vehicle_status' => null,
            'decision' => AccessDecisionOutcome::ALLOW,
            'reason_code' => 'STUDENT_ACTIVE',
            'priority' => 100,
            'enabled' => true,
            'valid_from' => null,
            'valid_until' => null,
        ];
    }
}
