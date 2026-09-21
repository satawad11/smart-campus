<?php

namespace Database\Factories;

use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use App\Models\AIModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AIModel>
 */
class AIModelFactory extends Factory
{
    protected $model = AIModel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // uuid is generated automatically by HasPublicUuid on create().
        return [
            'code' => 'MODEL_'.strtoupper(fake()->unique()->bothify('??_###')),
            'name' => fake()->words(3, true),
            'model_type' => AIModelType::FACE_RECOGNITION,
            'version' => 'v'.fake()->numerify('#.#.#'),
            'provider' => 'ONNX_RUNTIME',
            'artifact_path' => 'models/face/'.fake()->slug().'.onnx',
            'model_checksum' => hash('sha256', fake()->text()),
            'embedding_dimension' => 512,
            'distance_metric' => 'COSINE',
            'threshold_high' => 0.65,
            'threshold_low' => 0.45,
            'status' => AIModelStatus::ACTIVE,
            'is_default' => false,
            'config' => [
                'input_shape' => [1, 3, 112, 112],
                'mean' => [127.5, 127.5, 127.5],
                'std' => [127.5, 127.5, 127.5],
            ],
            'metadata' => [
                'framework' => 'onnxruntime',
                'description' => 'ArcFace ResNet-50 embedding extractor',
            ],
            'deployed_at' => now(),
        ];
    }
}
