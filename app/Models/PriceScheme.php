<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use App\Enums\PriceSchemeType;
use Database\Factories\PriceSchemeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Named set of quantity tiers that prices a group of products (COT-001, design Decisions 4 and 8). The
 * `type` is set when the scheme is created and never changes (DEC-COT-51); a fixed scheme has one
 * implicit priced tier. It is never deleted: it is deactivated.
 *
 * @property int $id
 * @property string $name
 * @property PriceSchemeType $type
 * @property CatalogStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PriceTier> $tiers
 * @property-read Collection<int, Product> $products
 */
#[Fillable(['name', 'type', 'status'])]
class PriceScheme extends Model
{
    /** @use HasFactory<PriceSchemeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PriceSchemeType::class,
            'status' => CatalogStatus::class,
        ];
    }

    /**
     * Tiers in their quantity order.
     *
     * @return HasMany<PriceTier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(PriceTier::class)->orderBy('sort_order');
    }

    /**
     * Products priced by this scheme (COT-002).
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
