<?php

namespace App\Models;

use App\Enums\AttributePresentation;
use App\Enums\AttributeSpecialUse;
use App\Enums\CatalogStatus;
use Database\Factories\CatalogAttributeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Attribute of the catalog, e.g. Tela, Talla, Color (PRD-002). Named `CatalogAttribute` to avoid the
 * clash with Eloquent's `Attribute` cast. The generated `color_marker` column is read-only and
 * backs the "one color attribute" rule (DEC-PRD-38).
 *
 * @property int $id
 * @property string $name
 * @property AttributePresentation $presentation
 * @property AttributeSpecialUse|null $special_use
 * @property int|null $color_marker
 * @property int $sort_order
 * @property CatalogStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AttributeValue> $values
 */
#[Fillable(['name', 'presentation', 'special_use', 'sort_order', 'status'])]
class CatalogAttribute extends Model
{
    /** @use HasFactory<CatalogAttributeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'presentation' => AttributePresentation::class,
            'special_use' => AttributeSpecialUse::class,
            'status' => CatalogStatus::class,
        ];
    }

    /**
     * @return HasMany<AttributeValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class)->orderBy('sort_order');
    }

    /**
     * @param  Builder<CatalogAttribute>  $query
     */
    public function scopeWithStatus(Builder $query, CatalogStatus $status): void
    {
        $query->where('status', $status->value);
    }

    /**
     * Name search that ignores accents as well as letter case; a blank term filters nothing.
     *
     * @param  Builder<CatalogAttribute>  $query
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
