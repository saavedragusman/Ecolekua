<?php

namespace Database\Factories;

use App\Models\Combo;
use App\Models\ComboComponent;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A component of one unit of a diaper product, without restrictions. All values are fictitious
 * (AGENTS.md section 8).
 *
 * @extends Factory<ComboComponent>
 */
class ComboComponentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'combo_id' => Combo::factory(),
            'product_id' => Product::factory()->diapers(),
            'quantity' => 1,
            'sort_order' => fake()->unique()->numberBetween(1, 1_000_000),
        ];
    }
}
