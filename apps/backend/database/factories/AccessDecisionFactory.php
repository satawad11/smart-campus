<?php

namespace Database\Factories;

use App\Enums\AccessDecisionOutcome;
use App\Enums\DecisionSource;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessDecision>
 */
class AccessDecisionFactory extends Factory
{
    protected $model = AccessDecision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => AccessEvent::factory(),
            'person_id' => null,
            'decision' => AccessDecisionOutcome::ALLOW,
            'reason_code' => 'DEFAULT_ALLOW',
            'decision_source' => DecisionSource::SYSTEM,
            'decided_by_user_id' => null,
            'reason_text' => null,
        ];
    }
}
