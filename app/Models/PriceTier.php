<?php

namespace App\Models;

use App\Enums\PriceTierKind;
use Database\Factories\PriceTierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Quantity range of a price scheme (COT-001, design Decision 8). `max_quantity` is null in the open
 * last tier. A `negotiation` tier carries no registered price.
 *
 * @property int $id
 * @property int $price_scheme_id
 * @property string $label
 * @property int $min_quantity
 * @property int|null $max_quantity
 * @property PriceTierKind $kind
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PriceScheme $scheme
 * @property-read Collection<int, CombinationPrice> $prices
 */
#[Fillable(['price_scheme_id', 'label', 'min_quantity', 'max_quantity', 'kind', 'sort_order'])]
class PriceTier extends Model
{
    /** @use HasFactory<PriceTierFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_scheme_id' => 'integer',
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
            'kind' => PriceTierKind::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PriceScheme, $this>
     */
    public function scheme(): BelongsTo
    {
        return $this->belongsTo(PriceScheme::class, 'price_scheme_id');
    }

    /**
     * @return HasMany<CombinationPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(CombinationPrice::class);
    }
}
