<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\DetailLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Place on a garment where a detail can go, e.g. "Pechera" (PRD-007). `svg_layer` links it to a
 * template layer (DT-03). Never deleted: it is deactivated.
 *
 * @property int $id
 * @property string $name
 * @property CatalogStatus $status
 * @property string|null $svg_layer
 * @property string|null $image_display_path
 * @property string|null $image_thumb_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'status', 'svg_layer', 'image_display_path', 'image_thumb_path'])]
class DetailLocation extends Model
{
    /** @use HasFactory<DetailLocationFactory> */
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
     * @param  Builder<DetailLocation>  $query
     */
    public function scopeWithStatus(Builder $query, CatalogStatus $status): void
    {
        $query->where('status', $status->value);
    }

    /**
     * Name search that ignores accents as well as letter case; a blank term filters nothing.
     *
     * @param  Builder<DetailLocation>  $query
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
