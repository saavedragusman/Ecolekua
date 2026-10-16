<?php

namespace App\Models;

use App\Casts\AsBigDecimal;
use Brick\Math\BigDecimal;
use Database\Factories\QuotationLineRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One size row of a product line (COT-014): the size (null for a product without sizes) and its quantity;
 * the unit price and size group label are frozen at issue. `size_key` is a read-only generated column that
 * only backs the one-row-per-size index.
 *
 * @property int $id
 * @property int $quotation_line_id
 * @property int|null $size_value_id
 * @property int $quantity
 * @property BigDecimal|null $unit_price
 * @property string|null $size_group_label
 * @property-read QuotationLine $line
 */
#[Fillable(['quotation_line_id', 'size_value_id', 'quantity', 'unit_price', 'size_group_label'])]
class QuotationLineRow extends Model
{
    /** @use HasFactory<QuotationLineRowFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quotation_line_id' => 'integer',
            'size_value_id' => 'integer',
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
