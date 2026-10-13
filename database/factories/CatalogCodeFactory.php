<?php

namespace Database\Factories;

use App\Models\CatalogCode;
use App\Models\Combination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A registry code owned by a combination. Combos get their own state when their table exists
 * (Phase 13). All values are fictitious (AGENTS.md section 8).
 *
 * @extends Factory<CatalogCode>
 */
class CatalogCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('C###-#'),
            'combination_id' => Combination::factory(),
            'combo_id' => null,
        ];
    }
}
