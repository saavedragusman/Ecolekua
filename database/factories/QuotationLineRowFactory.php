<?php

namespace Database\Factories;

use App\Models\QuotationLine;
use App\Models\QuotationLineRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One row without size of 1 unit. All values are fictitious (AGENTS.md section 8).
 *
 * @extends Factory<QuotationLineRow>
 */
class QuotationLineRowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quotation_line_id' => QuotationLine::factory(),
            'size_value_id' => null,
            'quantity' => 1,
        ];
    }
}
