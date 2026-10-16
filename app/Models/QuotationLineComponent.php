<?php

namespace App\Models;

use App\Casts\AsBigDecimal;
use Brick\Math\BigDecimal;
use Database\Factories\QuotationLineComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component of a combo line (COT-005): the selection made for it and the combination it resolved to;
 * code, name, quantity and item price are frozen at issue. The reference to the combo component is nulled if
 * the combo is edited later, because the frozen copy already holds what was quoted.
 *
 * @property int $id
 * @property int $quotation_line_id
 * @property int|null $combo_component_id
 * @property int|null $combination_id
 * @property int $position
 * @property array<string, mixed> $selection
 * @property string|null $code
 * @property string|null $descriptive_name
 * @property array<string, mixed>|null $normalized_selection
 * @property int|null $quantity
 * @property BigDecimal|null $unit_price
 * @property-read QuotationLine $line
 */
#[Fillable([
    'quotation_line_id', 'combo_component_id', 'combination_id', 'position', 'selection', 'code',
    'descriptive_name', 'normalized_selection', 'quantity', 'unit_price',
])]
class QuotationLineComponent extends Model
{
    /** @use HasFactory<QuotationLineComponentFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quotation_line_id' => 'integer',
            'combo_component_id' => 'integer',
            'combination_id' => 'integer',
            'position' => 'integer',
            'selection' => 'array',
            'normalized_selection' => 'array',
            'quantity' => 'integer',
            'unit_price' => AsBigDecimal::class.':2',
        ];
    }

    /**
     * @return BelongsTo<QuotationLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(QuotationLine::class, 'quotation_line_id');
    }
}
