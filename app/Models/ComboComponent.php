<?php

namespace App\Models;

use Database\Factories\ComboComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One line of a combo: a product, how many units of it and, per attribute, the values it admits
 * (PRD-010, DEC-PRD-44). An attribute without rows in `combo_component_values` is unrestricted.
 *
 * @property int $id
 * @property int $combo_id
 * @property int $product_id
 * @property int $quantity
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Combo $combo
 * @property-read Product $product
 */
#[Fillable(['combo_id', 'product_id', 'quantity', 'sort_order'])]
class ComboComponent extends Model
{
    /** @use HasFactory<ComboComponentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'combo_id' => 'integer',
            'product_id' => 'integer',
            'quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Combo, $this>
     */
    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Values the component admits, each with the attribute it belongs to.
     *
     * @return BelongsToMany<AttributeValue, $this>
     */
    public function values(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'combo_component_values', 'combo_component_id')->withPivot('catalog_attribute_id');
    }
}
