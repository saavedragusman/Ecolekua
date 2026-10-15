<?php

namespace Database\Factories;

use App\Enums\LineKind;
use App\Models\Combo;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A product line with an empty selection; `combo()` makes it a combo line of one combo. All values are
 * fictitious (AGENTS.md section 8).
 *
 * @extends Factory<QuotationLine>
 */
class QuotationLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quotation_id' => Quotation::factory(),
            'position' => fake()->unique()->numberBetween(1, 60_000),
            'kind' => LineKind::Product,
            'product_id' => Product::factory(),
            'combo_id' => null,
            'selection' => [],
        ];
    }

    public function combo(): static
    {
        return $this->state([
            'kind' => LineKind::Combo,
            'product_id' => null,
            'combo_id' => Combo::factory(),
            'combo_quantity' => 1,
        ]);
    }
}
