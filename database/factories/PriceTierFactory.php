<?php

namespace Database\Factories;

use App\Enums\PriceTierKind;
use App\Models\PriceScheme;
use App\Models\PriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open priced tier from one unit. Tests that need several tiers of one scheme pass explicit limits
 * and sort orders. All values are fictitious (AGENTS.md section 8).
 *
 * @extends Factory<PriceTier>
 */
class PriceTierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'price_scheme_id' => PriceScheme::factory(),
            'label' => 'Tramo '.fake()->unique()->bothify('??-###'),
            'min_quantity' => 1,
            'max_quantity' => null,
            'kind' => PriceTierKind::Priced,
            'sort_order' => 1,
        ];
    }

    public function negotiation(): static
    {
        return $this->state(['kind' => PriceTierKind::Negotiation]);
    }
}
