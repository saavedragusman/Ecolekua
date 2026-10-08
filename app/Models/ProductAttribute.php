<?php

namespace App\Models;

use App\Enums\AttributeRole;
use Database\Factories\ProductAttributeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * An attribute declared by a product with its role (axis or order) and the values the product
 * admits for it (PRD-004). Each attribute appears once per product (E-06).
 *
 * @property int $id
 * @property int $product_id
 * @property int $catalog_attribute_id
 * @property AttributeRole $role
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read CatalogAttribute $catalogAttribute
 */
#[Fillable(['product_id', 'catalog_attribute_id', 'role', 'sort_order'])]
class ProductAttribute extends Model
{
    /** @use HasFactory<ProductAttributeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AttributeRole::class,
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
     * @return BelongsTo<CatalogAttribute, $this>
     */
    public function catalogAttribute(): BelongsTo
    {
        return $this->belongsTo(CatalogAttribute::class);
    }

    /**
     * Values the product admits for this attribute.
     *
     * @return BelongsToMany<AttributeValue, $this>
     */
    public function allowedValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'product_attribute_values');
    }
}
