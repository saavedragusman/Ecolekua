<?php

namespace Database\Factories;

use App\Models\CatalogCode;
use App\Models\Combination;
use App\Models\Combo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A registry code owned by a combination, or by a combo with `forCombo()`. All values are
 * fictitious (AGENTS.md section 8).
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

    /**
     * The code of a combo instead of a combination (exactly one owner, DEC-PRD-14).
     */
    public function forCombo(Combo $combo): static
    {
        return $this->state(['combination_id' => null, 'combo_id' => $combo->id]);
    }
}
