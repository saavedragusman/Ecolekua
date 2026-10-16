<?php

namespace Database\Factories;

use App\Models\QuotationLine;
use App\Models\QuotationLineComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One component of a combo line with an empty selection. All values are fictitious (AGENTS.md section 8).
 *
 * @extends Factory<QuotationLineComponent>
 */
class QuotationLineComponentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quotation_line_id' => QuotationLine::factory()->combo(),
            'combo_component_id' => null,
            'combination_id' => null,
            'position' => 1,
            'selection' => [],
        ];
    }
}
