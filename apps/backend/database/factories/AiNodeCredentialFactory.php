<?php

namespace Database\Factories;

use App\Models\AiNode;
use App\Models\AiNodeCredential;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiNodeCredential>
 */
class AiNodeCredentialFactory extends Factory
{
    protected $model = AiNodeCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_node_id' => AiNode::factory(),
            'token_hash' => hash('sha256', Str::random(64)),
            'status' => 'ACTIVE',
            'last_used_at' => null,
            'revoked_at' => null,
        ];
    }
}
