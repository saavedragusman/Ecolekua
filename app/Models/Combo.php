<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\ComboFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Combo of the diaper line (PRD-010, design Decisions 3, 4 and 15): a named set of components with
 * a code of the shared registry. It stores no business line, which is implicit (DEC-PRD-43). It is
 * never deleted while it has history: it is deactivated.
 *
 * @property int $id
 * @property string $name
 * @property bool $portal_visible
 * @property CatalogStatus $status
 * @property-read string|null $code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CatalogCode|null $catalogCode
 * @property-read Collection<int, ComboComponent> $components
 */
#[Fillable(['name', 'portal_visible', 'status'])]
class Combo extends Model
{
    /** @use HasFactory<ComboFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'portal_visible' => 'boolean',
            'status' => CatalogStatus::class,
        ];
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
     * Components in display order.
     *
     * @return HasMany<ComboComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(ComboComponent::class)->orderBy('sort_order')->orderBy('id');
    }
}
