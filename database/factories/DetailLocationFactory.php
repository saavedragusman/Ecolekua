<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\DetailLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * All values are fictitious (AGENTS.md section 8).
 *
 * @extends Factory<DetailLocation>
 */
class DetailLocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Ubicación '.fake()->unique()->bothify('??-###'),
            'status' => CatalogStatus::Active,
            'svg_layer' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => CatalogStatus::Inactive]);
    }
}
