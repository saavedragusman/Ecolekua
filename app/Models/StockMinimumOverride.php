<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Own minimum stock of a combination, optionally for one size (design Decision 13, E-65). A row
 * without size applies to the whole combination. `size_key` is a generated read-only column that
 * makes the (combination, size) pair unique even when the size is null.
 *
 * @property int $id
 * @property int $combination_id
 * @property int|null $size_value_id
 * @property int $minimum
 * @property int $size_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Combination $combination
 * @property-read AttributeValue|null $sizeValue
 */
#[Fillable(['combination_id', 'size_value_id', 'minimum'])]
class StockMinimumOverride extends Model
{
    /**
     * @return BelongsTo<Combination, $this>
     */
    public function combination(): BelongsTo
    {
        return $this->belongsTo(Combination::class);
    }

    /**
     * @return BelongsTo<AttributeValue, $this>
     */
    public function sizeValue(): BelongsTo
    {
        return $this->belongsTo(AttributeValue::class, 'size_value_id');
    }
}
