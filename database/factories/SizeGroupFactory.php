<?php

namespace Database\Factories;

use App\Models\Combination;
use App\Models\SizeGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An empty size group of a new combination; tests attach its sizes with
 * `sizes()->attach($id, ['combination_id' => …])`. All values are fictitious (AGENTS.md section 8).
 *
 * @extends Factory<SizeGroup>
 */
class SizeGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'combination_id' => Combination::factory(),
            'sort_order' => fake()->unique()->numberBetween(1, 30_000),
        ];
    }
}
