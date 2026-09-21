<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    protected $model = Zone::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'parent_zone_id' => null,
            'code' => 'ZONE_'.Str::upper(Str::random(6)),
            'name' => fake()->words(2, true),
            'zone_type' => null,
            'status' => 'ACTIVE',
        ];
    }
}
