<?php

namespace App\Models;

use App\Casts\AsBigDecimal;
use Brick\Math\BigDecimal;
use Database\Factories\ComboItemPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Unit price of one combo component (COT-005, DEC-COT-06). The amount is a `BigDecimal` (DT-01) and
 * always positive (DEC-COT-02); the row goes with its component.
 *
 * @property int $id
 * @property int $combo_component_id
 * @property BigDecimal $unit_price
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ComboComponent $component
 * @property-read User|null $updatedBy
 */
#[Fillable(['combo_component_id', 'unit_price', 'updated_by'])]
class ComboItemPrice extends Model
{
    /** @use HasFactory<ComboItemPriceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'combo_component_id' => 'integer',
            'unit_price' => AsBigDecimal::class.':2',
            'updated_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ComboComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(ComboComponent::class, 'combo_component_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
