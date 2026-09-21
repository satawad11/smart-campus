<?php

namespace Database\Factories;

use App\Enums\FaceSamplePose;
use App\Enums\FaceSampleSource;
use App\Models\FaceProfile;
use App\Models\FaceSample;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FaceSample>
 */
class FaceSampleFactory extends Factory
{
    protected $model = FaceSample::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sampleUuid = (string) Str::uuid();

        // uuid is generated automatically by HasPublicUuid on create().
        return [
            'face_profile_id' => FaceProfile::factory(),
            'image_path' => 'biometrics/faces/samples/'.$sampleUuid.'.jpg',
            'pose' => FaceSamplePose::FRONT,
            'source' => FaceSampleSource::UPLOAD,
            'quality_score' => 0.92,
            'blur_score' => 0.05,
            'brightness_score' => 0.60,
            'face_size_score' => 0.85,
            'yaw' => 1.2,
            'pitch' => -0.5,
            'roll' => 0.1,
            'accepted' => true,
            'rejection_reason' => null,
            'checksum' => hash('sha256', fake()->text()),
            'width' => 640,
            'height' => 640,
            'mime_type' => 'image/jpeg',
            'captured_at' => now(),
        ];
    }
}
