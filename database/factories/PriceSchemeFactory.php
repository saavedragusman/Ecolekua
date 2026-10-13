<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Enums\PriceSchemeType;
use App\Enums\PriceTierKind;
use App\Models\PriceScheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active tiered scheme without tiers. `fixed()` and `negotiationLast()` also create their tiers.
 * All values are fictitious (AGENTS.md section 8).
 *
 * @extends Factory<PriceScheme>
 */
class PriceSchemeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Esquema '.fake()->unique()->bothify('??-###'),
            'type' => PriceSchemeType::Tiered,
            'status' => CatalogStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => CatalogStatus::Inactive]);
    }

    /**
     * A fixed-price scheme with its single implicit priced tier (COT-001).
     */
    public function fixed(): static
    {
        return $this->state(['type' => PriceSchemeType::Fixed])
            ->afterCreating(function (PriceScheme $scheme): void {
                $scheme->tiers()->create([
                    'label' => 'Precio',
                    'min_quantity' => 1,
                    'max_quantity' => null,
                    'kind' => PriceTierKind::Priced,
                    'sort_order' => 1,
                ]);
            });
    }

    /**
     * A tiered scheme with three priced tiers (1-6, 7-12, 13-49) and an open negotiation tier (50+).
     */
    public function negotiationLast(): static
    {
        return $this->afterCreating(function (PriceScheme $scheme): void {
            $tiers = [
                ['Detal', 1, 6, PriceTierKind::Priced],
                ['Intermedio', 7, 12, PriceTierKind::Priced],
                ['Mayor', 13, 49, PriceTierKind::Priced],
                ['Negociación', 50, null, PriceTierKind::Negotiation],
            ];

            foreach ($tiers as $position => [$label, $min, $max, $kind]) {
                $scheme->tiers()->create([
                    'label' => $label,
                    'min_quantity' => $min,
                    'max_quantity' => $max,
                    'kind' => $kind,
                    'sort_order' => $position + 1,
                ]);
            }
        });
    }
}
