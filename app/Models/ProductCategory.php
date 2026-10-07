<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\ProductCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Category of a product (PRD-001). Ordered by `sort_order`, never deleted: it is deactivated.
 *
 * @property int $id
 * @property string $name
 * @property int $sort_order
 * @property CatalogStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'sort_order', 'status'])]
class ProductCategory extends Model
{
    /** @use HasFactory<ProductCategoryFactory> */
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
     * @param  Builder<ProductCategory>  $query
     */
    public function scopeWithStatus(Builder $query, CatalogStatus $status): void
    {
        $query->where('status', $status->value);
    }

    /**
     * Name search that ignores accents as well as letter case (design Decision 3, "Collation"). `%`
     * and `_` are escaped so they match literally; a blank term filters nothing.
     *
     * @param  Builder<ProductCategory>  $query
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
