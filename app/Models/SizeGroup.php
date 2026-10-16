<?php

namespace App\Models;

use Database\Factories\SizeGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Set of sizes of a combination that share a price (COT-004, design Decisions 4 and 10). A size
 * belongs to at most one group of the combination; the registry repeats `combination_id` to
 * guarantee it, so attaching a size must pass `['combination_id' => …]` as pivot data.
 *
 * @property int $id
 * @property int $combination_id
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Combination $combination
 * @property-read Collection<int, AttributeValue> $sizes
 * @property-read Collection<int, CombinationPrice> $prices
 */
#[Fillable(['combination_id', 'sort_order'])]
class SizeGroup extends Model
{
    /** @use HasFactory<SizeGroupFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'combination_id' => 'integer',
            'sort_order' => 'integer',
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
     * Sizes of the group, as values of the size attribute.
     *
     * @return BelongsToMany<AttributeValue, $this>
     */
    public function sizes(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'size_group_values', 'size_group_id', 'size_value_id')->withPivot('combination_id');
    }

    /**
     * @return HasMany<CombinationPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(CombinationPrice::class);
    }
}
