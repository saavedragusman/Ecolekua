<?php

namespace Database\Factories;

use App\Models\ComboComponent;
use App\Models\ComboItemPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A price of 10.00 for a new combo component. All values are fictitious (AGENTS.md section 8).
 *
 * @extends Factory<ComboItemPrice>
 */
class ComboItemPriceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'combo_component_id' => ComboComponent::factory(),
            'unit_price' => '10.00',
            'updated_by' => null,
        ];
    }
}
