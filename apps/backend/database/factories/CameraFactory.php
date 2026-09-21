<?php

namespace Database\Factories;

use App\Models\Camera;
use App\Models\Site;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Camera>
 */
class CameraFactory extends Factory
{
    protected $model = Camera::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Created eagerly (not lazily via Site::factory()) so the default
        // zone genuinely belongs to the default site — two independent lazy
        // factory calls would otherwise resolve to two different sites.
        $site = Site::factory()->create();

        return [
            'site_id' => $site->id,
            'zone_id' => Zone::factory()->for($site)->create()->id,
            'ai_node_id' => null,
            'camera_code' => 'CAM_'.Str::upper(Str::random(6)),
            'name' => fake()->words(2, true),
            'camera_type' => 'FIXED',
            'ip_address' => fake()->localIpv4(),
            'stream_protocol' => 'RTSP',
            'rtsp_url_encrypted' => null,
            'username_encrypted' => null,
            'password_encrypted' => null,
            'onvif_url' => null,
            'face_detection_enabled' => false,
            'face_recognition_enabled' => false,
            'liveness_enabled' => false,
            'snapshot_enabled' => false,
            'status' => 'ACTIVE',
            'last_seen_at' => null,
        ];
    }
}
