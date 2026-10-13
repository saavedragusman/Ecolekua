<?php

namespace App\Models;

use Database\Factories\CatalogCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Registry row of a commercial code, unique across combinations and combos and case-insensitive
 * (DEC-PRD-01, DEC-PRD-14, DT-01, E-08). Exactly one of `combination_id` and `combo_id` is set; a
 * CHECK constraint guarantees it.
 *
 * @property int $id
 * @property string $code
 * @property int|null $combination_id
 * @property int|null $combo_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Combination|null $combination
 */
#[Fillable(['code', 'combination_id', 'combo_id'])]
class CatalogCode extends Model
{
    /** @use HasFactory<CatalogCodeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Combination, $this>
     */
    public function combination(): BelongsTo
    {
        return $this->belongsTo(Combination::class);
    }
}
