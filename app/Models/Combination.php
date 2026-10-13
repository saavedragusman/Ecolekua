<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\CombinationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Commercial combination of a product: the values of its axes, identified by a code of the shared
 * registry (PRD-005, design Decisions 3, 4 and 12). `active_signature` is a generated read-only
 * column; `axis_signature` is written by the Actions. Its axis values and its order restrictions
 * live in `combination_values`; the role of each attribute is the one the product declares.
 *
 * @property int $id
 * @property int $product_id
 * @property string|null $description
 * @property CatalogStatus $status
 * @property string $axis_signature
 * @property string|null $active_signature
 * @property-read string|null $code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read CatalogCode|null $catalogCode
 * @property-read Collection<int, AttributeValue> $values
 * @property-read Collection<int, Product> $customizations
 */
#[Fillable(['product_id', 'description', 'status', 'axis_signature'])]
class Combination extends Model
{
    /** @use HasFactory<CombinationFactory> */
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The code as stored in the registry (DT-01), or null while the registry row does not exist.
     *
     * @return Attribute<string|null, never>
     */
    protected function code(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->catalogCode?->code);
    }

    /**
     * Registry row that holds the code (DT-01). Named `catalogCode` so it does not clash with the
     * `code` accessor.
     *
     * @return HasOne<CatalogCode, $this>
     */
    public function catalogCode(): HasOne
    {
        return $this->hasOne(CatalogCode::class);
    }

    /**
     * Axis values and order restrictions of the combination, each with the attribute it belongs to.
     *
     * @return BelongsToMany<AttributeValue, $this>
     */
    public function values(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'combination_values')->withPivot('catalog_attribute_id');
    }

    /**
     * Services (products of mode `service`) whose price the combination already covers (PRD-008,
     * DEC-PRD-47). Independent of the customizations the product admits as extras.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function customizations(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'combination_customizations', 'combination_id', 'service_product_id');
    }

    /**
     * Stock minimum overrides keyed by size (Decision 13).
     *
     * @return HasMany<StockMinimumOverride, $this>
     */
    public function stockMinimumOverrides(): HasMany
    {
        return $this->hasMany(StockMinimumOverride::class);
    }
}
