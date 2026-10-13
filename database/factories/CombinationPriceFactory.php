<?php

namespace Database\Factories;

use App\Models\Combination;
use App\Models\CombinationPrice;
use App\Models\PriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An ungrouped price of 40.00 for a new combination and tier. All values are fictitious
 * (AGENTS.md section 8).
 *
 * @extends Factory<CombinationPrice>
 */
class CombinationPriceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'combination_id' => Combination::factory(),
            'price_tier_id' => PriceTier::factory(),
            'size_group_id' => null,
            'unit_price' => '40.00',
            'updated_by' => null,
        ];
    }
}
