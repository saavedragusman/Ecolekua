<?php

namespace App\Models;

use App\Casts\AsBigDecimal;
use App\Enums\LineKind;
use App\Enums\PriceOrigin;
use Brick\Math\BigDecimal;
use Database\Factories\QuotationLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One line of a quotation (COT-013, COT-014): a product with its 003 selection and one row per size, or a
 * combo with its components. `combination_id` is the combination resolved at the last save, the negotiation
 * fields keep the negotiated price's author and basis (DEC-COT-55), and the frozen columns are filled at issue.
 *
 * @property int $id
 * @property int $quotation_id
 * @property int $position
 * @property LineKind $kind
 * @property int|null $product_id
 * @property int|null $combo_id
 * @property int|null $combination_id
 * @property array<string, mixed> $selection
 * @property int|null $combo_quantity
 * @property string|null $note
 * @property int|null $negotiated_by
 * @property Carbon|null $negotiated_at
 * @property bool $negotiation_confirmed
 * @property int|null $negotiated_combination_id
 * @property list<int>|null $negotiated_size_group_ids
 * @property string|null $code
 * @property string|null $descriptive_name
 * @property array<string, mixed>|null $normalized_selection
 * @property string|null $tier_label
 * @property PriceOrigin|null $price_origin
 * @property BigDecimal|null $subtotal
 * @property bool $requires_advisor
 * @property string|null $advisor_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Quotation $quotation
 * @property-read Collection<int, QuotationLineRow> $rows
 * @property-read Collection<int, QuotationLineComponent> $components
 * @property-read Collection<int, Product> $customizations
 */
#[Fillable([
    'quotation_id', 'position', 'kind', 'product_id', 'combo_id', 'combination_id', 'selection',
    'combo_quantity', 'note', 'negotiated_by', 'negotiated_at', 'negotiation_confirmed',
    'negotiated_combination_id', 'negotiated_size_group_ids', 'code', 'descriptive_name',
    'normalized_selection', 'tier_label', 'price_origin', 'subtotal', 'requires_advisor', 'advisor_reason',
])]
class QuotationLine extends Model
{
    /** @use HasFactory<QuotationLineFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quotation_id' => 'integer',
            'position' => 'integer',
            'kind' => LineKind::class,
            'product_id' => 'integer',
            'combo_id' => 'integer',
            'combination_id' => 'integer',
            'selection' => 'array',
            'combo_quantity' => 'integer',
            'negotiated_by' => 'integer',
            'negotiated_at' => 'datetime',
            'negotiation_confirmed' => 'boolean',
            'negotiated_combination_id' => 'integer',
            'negotiated_size_group_ids' => 'array',
            'normalized_selection' => 'array',
            'price_origin' => PriceOrigin::class,
            'subtotal' => AsBigDecimal::class.':2',
            'requires_advisor' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Combo, $this>
     */
    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class);
    }

    /**
     * @return BelongsTo<Combination, $this>
     */
    public function combination(): BelongsTo
    {
        return $this->belongsTo(Combination::class);
    }

    /**
     * @return HasMany<QuotationLineRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(QuotationLineRow::class)->orderBy('id');
    }

    /**
     * Components of a combo line, in their position order.
     *
     * @return HasMany<QuotationLineComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(QuotationLineComponent::class)->orderBy('position');
    }

    /**
     * Customizations added to every garment of the line, with their quantity per garment (COT-011).
     *
     * @return BelongsToMany<Product, $this>
     */
    public function customizations(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'quotation_line_customizations', 'quotation_line_id', 'service_product_id')
            ->withPivot('quantity_per_garment');
    }
}
