<?php

namespace App\Models;

use App\Casts\AsBigDecimal;
use Brick\Math\BigDecimal;
use Database\Factories\CombinationPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Unit price of a combination in one tier and, for grouped sizes, one size group (COT-003, COT-004,
 * design Decision 4). The amount is a `BigDecimal` (DT-01) and always positive (DEC-COT-02).
 * `size_group_key` is a read-only generated column that only backs the unique cell index.
 *
 * @property int $id
 * @property int $combination_id
 * @property int $price_tier_id
 * @property int|null $size_group_id
 * @property BigDecimal $unit_price
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Combination $combination
 * @property-read PriceTier $tier
 * @property-read SizeGroup|null $sizeGroup
 * @property-read User|null $updatedBy
 */
#[Fillable(['combination_id', 'price_tier_id', 'size_group_id', 'unit_price', 'updated_by'])]
class CombinationPrice extends Model
{
    /** @use HasFactory<CombinationPriceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'combination_id' => 'integer',
            'price_tier_id' => 'integer',
            'size_group_id' => 'integer',
            'unit_price' => AsBigDecimal::class.':2',
            'updated_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Combination, $this>
     */
    public function combination(): BelongsTo
    {
        return $this->belongsTo(Combination::class);
    }

    /**
     * @return BelongsTo<PriceTier, $this>
     */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class, 'price_tier_id');
    }

    /**
     * @return BelongsTo<SizeGroup, $this>
     */
    public function sizeGroup(): BelongsTo
    {
        return $this->belongsTo(SizeGroup::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
