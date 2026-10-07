<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\AttributeValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Value of a catalog attribute, e.g. "Drill" for Tela (PRD-002). `tone` (`#RRGGBB`) is only used by
 * the color-presentation attribute; `svg_layer` links the value to a template layer (DT-03). The
 * offered colors of a fabric value live in `fabric_offered_colors`.
 *
 * @property int $id
 * @property int $catalog_attribute_id
 * @property string $name
 * @property string|null $description
 * @property int $sort_order
 * @property CatalogStatus $status
 * @property string|null $tone
 * @property string|null $svg_layer
 * @property string|null $image_display_path
 * @property string|null $image_thumb_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CatalogAttribute $catalogAttribute
 * @property-read Collection<int, AttributeValue> $offeredColors
 */
#[Fillable([
    'catalog_attribute_id', 'name', 'description', 'sort_order', 'status',
    'tone', 'svg_layer', 'image_display_path', 'image_thumb_path',
])]
class AttributeValue extends Model
{
    /** @use HasFactory<AttributeValueFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
        ];
    }

    /**
     * The attribute this value belongs to. Not called `attribute` because that name belongs to Eloquent.
     *
     * @return BelongsTo<CatalogAttribute, $this>
     */
    public function catalogAttribute(): BelongsTo
    {
        return $this->belongsTo(CatalogAttribute::class);
    }

    /**
     * Colors offered with this value when it is a fabric (PRD-002, E-46).
     *
     * @return BelongsToMany<AttributeValue, $this>
     */
    public function offeredColors(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'fabric_offered_colors', 'fabric_value_id', 'color_value_id');
    }

    /**
     * @param  Builder<AttributeValue>  $query
     */
    public function scopeWithStatus(Builder $query, CatalogStatus $status): void
    {
        $query->where('status', $status->value);
    }

    /**
     * Name search that ignores accents as well as letter case; a blank term filters nothing.
     *
     * @param  Builder<AttributeValue>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $query->whereRaw("`name` collate utf8mb4_unicode_ci like ? escape '\\\\'", ['%'.addcslashes($term, '\\%_').'%']);
    }
}
