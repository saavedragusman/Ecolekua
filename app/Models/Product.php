<?php

namespace App\Models;

use App\Enums\AttributePresentation;
use App\Enums\BusinessLine;
use App\Enums\CatalogStatus;
use App\Enums\SupplyMode;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Product of the catalog (PRD-003). Never deleted while it has history: it is deactivated. The
 * default minimum stock only exists in mode `stock_with_minimum` (DEC-PRD-46) and custom color is
 * only stored for `on_demand` (DEC-PRD-34); both rules are also CHECK constraints. The relation to
 * the declared attributes is `productAttributes()` because `attributes` belongs to Eloquent.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $product_category_id
 * @property BusinessLine $business_line
 * @property SupplyMode $supply_mode
 * @property int|null $min_stock_default
 * @property bool $allows_custom_color
 * @property bool $portal_visible
 * @property CatalogStatus $status
 * @property string|null $image_display_path
 * @property string|null $image_thumb_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductCategory $category
 * @property-read Collection<int, ProductAttribute> $productAttributes
 * @property-read Collection<int, DetailLocation> $detailLocations
 * @property-read Collection<int, Product> $customizations
 */
#[Fillable([
    'name', 'description', 'product_category_id', 'business_line', 'supply_mode',
    'min_stock_default', 'allows_custom_color', 'portal_visible', 'status',
    'image_display_path', 'image_thumb_path',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_line' => BusinessLine::class,
            'supply_mode' => SupplyMode::class,
            'allows_custom_color' => 'boolean',
            'portal_visible' => 'boolean',
            'status' => CatalogStatus::class,
        ];
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /**
     * Attributes the product declares, in display order (PRD-004).
     *
     * @return HasMany<ProductAttribute, $this>
     */
    public function productAttributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Detail locations the product admits (PRD-007).
     *
     * @return BelongsToMany<DetailLocation, $this>
     */
    public function detailLocations(): BelongsToMany
    {
        return $this->belongsToMany(DetailLocation::class, 'product_detail_locations');
    }

    /**
     * Services (products of mode `service`) the product admits as customizations (DEC-PRD-08).
     *
     * @return BelongsToMany<Product, $this>
     */
    public function customizations(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_customizations', 'product_id', 'service_product_id');
    }

    /**
     * Effective custom color (DEC-PRD-34): the "Personalizado" option is offered only when the mode
     * is `on_demand`, the team did not disable it and the product declares the color attribute. It
     * is computed, never stored.
     */
    public function admitsCustomColor(): bool
    {
        return $this->supply_mode === SupplyMode::OnDemand
            && $this->allows_custom_color
            && $this->productAttributes()
                ->whereHas('catalogAttribute', fn (Builder $query) => $query->where('presentation', AttributePresentation::Color->value))
                ->exists();
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeWithStatus(Builder $query, CatalogStatus $status): void
    {
        $query->where('status', $status->value);
    }

    /**
     * Name search that ignores accents as well as letter case (design Decision 3, "Collation"). `%`
     * and `_` are escaped so they match literally; a blank term filters nothing.
     *
     * @param  Builder<Product>  $query
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
