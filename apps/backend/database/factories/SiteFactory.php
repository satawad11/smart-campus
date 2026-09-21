<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'SITE_'.Str::upper(Str::random(6)),
            'name' => fake()->company(),
            'address' => null,
            'timezone' => 'Asia/Bangkok',
            'status' => 'ACTIVE',
        ];
    }
}
